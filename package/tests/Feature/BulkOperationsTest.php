<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\User;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Support\BulkUserAssignment;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class BulkOperationsTest extends TestCase
{
    public function test_bulk_add_floating_creates_allocations_and_spreads_cost()
    {
        $license = License::factory()->create();
        $this->createFloatingConfig($license, [
            'cost_mode' => FloatingLicenseConfig::COST_MODE_ACTIVE_USER,
            'total_cost' => 300,
            'allow_over_allocation' => true,
        ]);
        $allocator = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $users = User::factory()->count(3)->create();

        $this->actingAs($allocator)
            ->post(route('floating-licenses.license.bulk-add', $license), [
                'user_ids' => $users->pluck('id')->all(),
            ])
            ->assertRedirect(route('licenses.show', $license))
            ->assertSessionHas('success');

        $this->assertEquals(
            3,
            FloatingLicenseAllocation::where('license_id', $license->id)->active()->count()
        );

        foreach ($users as $user) {
            $this->assertDatabaseHas('floating_license_allocations', [
                'license_id' => $license->id,
                'user_id' => $user->id,
                'status' => 'active',
                'allocated_cost' => 100.0,
            ]);
        }
    }

    public function test_bulk_add_floating_skips_users_who_already_have_an_allocation()
    {
        $license = License::factory()->create();
        $config = $this->createFloatingConfig($license, ['allow_over_allocation' => true]);
        $allocator = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $existing = User::factory()->create();
        app(FloatingLicenseService::class)->allocate($config, $existing);
        $newUser = User::factory()->create();

        $this->actingAs($allocator)
            ->post(route('floating-licenses.license.bulk-add', $license), [
                'user_ids' => [$existing->id, $newUser->id],
            ])
            ->assertSessionHas('success', trans('floating-licenses::floating.message.bulk_add_result', [
                'added' => 1,
                'skipped' => 1,
                'failed' => 0,
            ]));

        $this->assertEquals(
            2,
            FloatingLicenseAllocation::where('license_id', $license->id)->active()->count(),
            'The already-allocated user must not get a duplicate allocation'
        );
        $this->assertEquals(
            1,
            FloatingLicenseAllocation::where('license_id', $license->id)->where('user_id', $existing->id)->active()->count()
        );
    }

    public function test_bulk_add_floating_fails_gracefully_when_pool_exhausted()
    {
        $license = License::factory()->create();
        $this->createFloatingConfig($license, ['pool_size' => 1, 'allow_over_allocation' => false]);
        $allocator = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $users = User::factory()->count(2)->create();

        $this->actingAs($allocator)
            ->post(route('floating-licenses.license.bulk-add', $license), [
                'user_ids' => $users->pluck('id')->all(),
            ])
            ->assertSessionHas('warning');

        $this->assertEquals(
            1,
            FloatingLicenseAllocation::where('license_id', $license->id)->active()->count()
        );
    }

    public function test_bulk_remove_floating_releases_allocations()
    {
        $license = License::factory()->create();
        $config = $this->createFloatingConfig($license, ['allow_over_allocation' => true]);
        $releaser = $this->createUserWithFloatingPermissions(['floating_licenses.release']);
        $users = User::factory()->count(2)->create();
        $service = app(FloatingLicenseService::class);
        foreach ($users as $user) {
            $service->allocate($config, $user);
        }

        $this->actingAs($releaser)
            ->post(route('floating-licenses.license.bulk-remove', $license), [
                'user_ids' => $users->pluck('id')->all(),
            ])
            ->assertRedirect(route('licenses.show', $license))
            ->assertSessionHas('success');

        $this->assertEquals(
            0,
            FloatingLicenseAllocation::where('license_id', $license->id)->active()->count()
        );
        $this->assertEquals(
            2,
            FloatingLicenseAllocation::where('license_id', $license->id)
                ->where('status', FloatingLicenseAllocation::STATUS_RELEASED)->count()
        );
    }

    /**
     * Master switch ON means every license behaves floating: a license with
     * no explicit config gets one lazily created (seats = pool, purchase_cost
     * = total, active_user spread, over-allocation on) and bulk-add creates
     * floating allocations instead of core seat checkouts.
     */
    public function test_bulk_add_license_without_config_creates_floating_allocations_with_cost_spread()
    {
        $license = License::factory()->create(['seats' => 2, 'purchase_cost' => 300]);
        $allocator = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $users = User::factory()->count(3)->create();

        $this->assertEquals(0, FloatingLicenseConfig::where('license_id', $license->id)->count());

        $this->actingAs($allocator)
            ->post(route('floating-licenses.license.bulk-add', $license), [
                'user_ids' => $users->pluck('id')->all(),
            ])
            ->assertRedirect(route('licenses.show', $license))
            ->assertSessionHas('success');

        $config = FloatingLicenseConfig::where('license_id', $license->id)->first();
        $this->assertNotNull($config, 'A default config must be lazily created and persisted');
        $this->assertEquals(2, $config->pool_size);
        $this->assertEquals(300.0, (float) $config->total_cost);
        $this->assertEquals(FloatingLicenseConfig::COST_MODE_ACTIVE_USER, $config->cost_mode);
        $this->assertTrue($config->allow_over_allocation);

        // Over-allocated: 3 users on a 2-seat pool, cost spread across all 3.
        $this->assertEquals(
            3,
            FloatingLicenseAllocation::where('license_id', $license->id)->active()->count()
        );
        foreach ($users as $user) {
            $this->assertDatabaseHas('floating_license_allocations', [
                'license_id' => $license->id,
                'user_id' => $user->id,
                'status' => 'active',
                'allocated_cost' => 100.0,
            ]);
        }

        // No core seat checkouts may happen under master-on.
        $this->assertEquals(0, LicenseSeat::where('license_id', $license->id)->whereNotNull('assigned_to')->count());
    }

    public function test_bulk_remove_checks_in_core_seats()
    {
        $license = License::factory()->create(['seats' => 2]);
        $releaser = $this->createUserWithFloatingPermissions(['floating_licenses.release']);
        $users = User::factory()->count(2)->create();

        foreach ($users as $user) {
            $seat = $license->freeSeat();
            $seat->assigned_to = $user->id;
            $seat->save();
        }

        $this->actingAs($releaser)
            ->post(route('floating-licenses.license.bulk-remove', $license), [
                'user_ids' => $users->pluck('id')->all(),
            ])
            ->assertRedirect(route('licenses.show', $license))
            ->assertSessionHas('success');

        $this->assertEquals(0, LicenseSeat::where('license_id', $license->id)->whereNotNull('assigned_to')->count());
    }

    public function test_bulk_remove_handles_mixed_floating_and_seat_assignments()
    {
        $license = License::factory()->create(['seats' => 2]);
        $config = $this->createFloatingConfig($license);
        $releaser = $this->createUserWithFloatingPermissions(['floating_licenses.release']);

        $floatingUser = User::factory()->create();
        app(FloatingLicenseService::class)->allocate($config, $floatingUser);

        $seatUser = User::factory()->create();
        $seat = $license->freeSeat();
        $seat->assigned_to = $seatUser->id;
        $seat->save();

        $this->actingAs($releaser)
            ->post(route('floating-licenses.license.bulk-remove', $license), [
                'user_ids' => [$floatingUser->id, $seatUser->id],
            ])
            ->assertSessionHas('success', trans('floating-licenses::floating.message.bulk_remove_result', [
                'removed' => 2,
                'skipped' => 0,
                'failed' => 0,
            ]));

        $this->assertEquals(
            FloatingLicenseAllocation::STATUS_RELEASED,
            FloatingLicenseAllocation::where('license_id', $license->id)->where('user_id', $floatingUser->id)->sole()->status
        );
        $this->assertEquals(0, LicenseSeat::where('license_id', $license->id)->whereNotNull('assigned_to')->count());
    }

    /**
     * Regression: with the master switch OFF, the web routes 403, and the
     * assignment layer falls back to normal core seat checkout for a license
     * without a pool config.
     */
    public function test_bulk_add_fixed_license_with_master_off_does_core_seat_checkout()
    {
        $this->disableFloatingLicenses();

        $license = License::factory()->create(['seats' => 2]);
        $admin = User::factory()->superuser()->create();
        $users = User::factory()->count(2)->create();

        $this->actingAs($admin)
            ->post(route('floating-licenses.license.bulk-add', $license), [
                'user_ids' => $users->pluck('id')->all(),
            ])
            ->assertForbidden();

        $this->actingAs($admin);
        $result = app(BulkUserAssignment::class)->addUsers($license, $users->pluck('id')->all());

        $this->assertEquals(['added' => 2, 'skipped' => 0, 'failed' => 0], $result);

        foreach ($users as $user) {
            $this->assertDatabaseHas('license_seats', [
                'license_id' => $license->id,
                'assigned_to' => $user->id,
            ]);
            $this->assertDatabaseHas('action_logs', [
                'item_type' => License::class,
                'item_id' => $license->id,
                'target_type' => User::class,
                'target_id' => $user->id,
                'action_type' => 'checkout',
            ]);
        }

        $this->assertEquals(0, FloatingLicenseAllocation::count());
        $this->assertEquals(0, FloatingLicenseConfig::where('license_id', $license->id)->count(),
            'No config may be lazily created while the master switch is off');
    }

    public function test_bulk_add_form_page_renders()
    {
        $license = License::factory()->create();
        $allocator = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $user = User::factory()->create();

        $this->actingAs($allocator)
            ->get(route('floating-licenses.license.bulk-add.form', $license))
            ->assertOk()
            ->assertSee($user->username);
    }

    public function test_bulk_remove_form_page_lists_both_assignment_groups()
    {
        $license = License::factory()->create(['seats' => 1]);
        $config = $this->createFloatingConfig($license);
        $releaser = $this->createUserWithFloatingPermissions(['floating_licenses.release']);

        $floatingUser = User::factory()->create();
        app(FloatingLicenseService::class)->allocate($config, $floatingUser);

        $seatUser = User::factory()->create();
        $seat = $license->freeSeat();
        $seat->assigned_to = $seatUser->id;
        $seat->save();

        $this->actingAs($releaser)
            ->get(route('floating-licenses.license.bulk-remove.form', $license))
            ->assertOk()
            ->assertSee(trans('floating-licenses::floating.floating_assignments'))
            ->assertSee(trans('floating-licenses::floating.seat_assignments'))
            ->assertSee($floatingUser->username)
            ->assertSee($seatUser->username);
    }

    public function test_bulk_add_requires_allocate_permission()
    {
        $license = License::factory()->create();
        $viewer = $this->createUserWithFloatingPermissions(['floating_licenses.view']);

        $this->actingAs($viewer)
            ->post(route('floating-licenses.license.bulk-add', $license), [
                'user_ids' => [User::factory()->create()->id],
            ])
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('floating-licenses.license.bulk-add.form', $license))
            ->assertForbidden();
    }

    public function test_bulk_remove_requires_release_permission()
    {
        $license = License::factory()->create();
        $allocator = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);

        $this->actingAs($allocator)
            ->post(route('floating-licenses.license.bulk-remove', $license), [
                'user_ids' => [User::factory()->create()->id],
            ])
            ->assertForbidden();

        $this->actingAs($allocator)
            ->get(route('floating-licenses.license.bulk-remove.form', $license))
            ->assertForbidden();
    }

    public function test_bulk_add_validates_user_ids()
    {
        $license = License::factory()->create();
        $allocator = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);

        $this->actingAs($allocator)
            ->post(route('floating-licenses.license.bulk-add', $license), [])
            ->assertSessionHasErrors('user_ids');
    }
}
