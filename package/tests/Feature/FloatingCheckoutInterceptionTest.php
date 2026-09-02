<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\License;
use App\Models\LicenseSeat;
use App\Models\User;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class FloatingCheckoutInterceptionTest extends TestCase
{
    public function test_single_checkout_on_floating_license_creates_allocation_not_seat()
    {
        $license = License::factory()->create(['seats' => 3]);
        $config = $this->createFloatingConfig($license, ['pool_size' => 3]);
        $admin = User::factory()->superuser()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('licenses.checkout.save', $license->id), [
                'assigned_to' => $target->id,
                'notes' => 'single checkout',
            ])
            ->assertRedirect(route('licenses.show', $license->id))
            ->assertSessionHas('success', trans('admin/licenses/message.checkout.success'));

        $this->assertDatabaseHas('floating_license_allocations', [
            'license_id' => $license->id,
            'user_id' => $target->id,
            'status' => 'active',
        ]);

        $this->assertEquals(
            0,
            LicenseSeat::where('license_id', $license->id)->whereNotNull('assigned_to')->count(),
            'A floating checkout must not occupy a license_seats record'
        );
    }

    public function test_single_checkout_at_capacity_without_over_allocation_fails_cleanly()
    {
        $license = License::factory()->create(['seats' => 1]);
        $config = $this->createFloatingConfig($license, [
            'pool_size' => 1,
            'allow_over_allocation' => false,
        ]);
        app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

        $admin = User::factory()->superuser()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('licenses.checkout.save', $license->id), [
                'assigned_to' => $target->id,
            ])
            ->assertRedirect(route('licenses.show', $license->id))
            ->assertSessionHas('error', trans('floating-licenses::floating.error.pool_exhausted'));

        $this->assertEquals(
            1,
            FloatingLicenseAllocation::where('license_id', $license->id)->active()->count(),
            'No second allocation may be created'
        );
        $this->assertEquals(
            0,
            LicenseSeat::where('license_id', $license->id)->whereNotNull('assigned_to')->count()
        );
    }

    public function test_checkout_page_shows_floating_availability()
    {
        $license = License::factory()->create(['seats' => 35]);
        $config = $this->createFloatingConfig($license, ['pool_size' => 35, 'allow_over_allocation' => true]);
        $service = app(FloatingLicenseService::class);
        foreach (range(1, 2) as $i) {
            $service->allocate($config, User::factory()->create());
        }

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('licenses.checkout', $license))
            ->assertOk()
            ->assertSee(trans('admin/licenses/message.seats_available', ['seat_count' => 33]))
            ->assertDontSee(trans('floating-licenses::floating.error.pool_exhausted'));
    }

    public function test_checkout_page_shows_exhaustion_callout_when_pool_full()
    {
        $license = License::factory()->create(['seats' => 1]);
        $config = $this->createFloatingConfig($license, [
            'pool_size' => 1,
            'allow_over_allocation' => false,
        ]);
        app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('licenses.checkout', $license))
            ->assertOk()
            ->assertSee(trans('floating-licenses::floating.error.pool_exhausted'));
    }

    public function test_checkout_page_is_untouched_when_master_switch_off()
    {
        $license = License::factory()->create(['seats' => 7]);
        $this->createFloatingConfig($license, ['pool_size' => 7]);

        $this->disableFloatingLicenses();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('licenses.checkout', $license))
            ->assertOk()
            ->assertSee(trans('admin/licenses/message.seats_available', ['seat_count' => 7]))
            ->assertDontSee(trans('floating-licenses::floating.error.pool_exhausted'));
    }

    public function test_view_page_shows_checkin_button_per_floating_row()
    {
        $license = License::factory()->create();
        $config = $this->createFloatingConfig($license);
        $admin = User::factory()->superuser()->create();
        $allocation = app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

        $this->actingAs($admin)
            ->get(route('licenses.show', $license))
            ->assertOk()
            ->assertSee(route('floating-licenses.allocations.release', $allocation), false);
    }

    public function test_web_release_from_license_page_redirects_back_and_releases()
    {
        $license = License::factory()->create();
        $config = $this->createFloatingConfig($license);
        $releaser = $this->createUserWithFloatingPermissions(['floating_licenses.release']);
        $allocation = app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

        $this->actingAs($releaser)
            ->from(route('licenses.show', $license))
            ->post(route('floating-licenses.allocations.release', $allocation))
            ->assertRedirect(route('licenses.show', $license));

        $this->assertEquals(FloatingLicenseAllocation::STATUS_RELEASED, $allocation->refresh()->status);
    }

    public function test_web_release_without_referer_still_lands_on_pool_page()
    {
        $config = $this->createFloatingConfig();
        $user = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $allocation = app(FloatingLicenseService::class)->allocate($config, $user);

        $this->actingAs($user)
            ->post(route('floating-licenses.allocations.release', $allocation))
            ->assertRedirect(route('floating-licenses.show', $config));
    }

    public function test_checkout_on_fixed_license_with_master_off_does_normal_seat_checkout()
    {
        $this->disableFloatingLicenses();

        $license = License::factory()->create(['seats' => 2]);
        $admin = User::factory()->superuser()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('licenses.checkout.save', $license->id), [
                'assigned_to' => $target->id,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('license_seats', [
            'license_id' => $license->id,
            'assigned_to' => $target->id,
        ]);
        $this->assertDatabaseHas('action_logs', [
            'item_type' => License::class,
            'item_id' => $license->id,
            'target_type' => User::class,
            'target_id' => $target->id,
            'action_type' => 'checkout',
        ]);
        $this->assertEquals(0, FloatingLicenseAllocation::count());
    }

    public function test_checkout_with_master_off_is_not_intercepted_even_with_a_persisted_config()
    {
        $license = License::factory()->create(['seats' => 2]);
        $this->createFloatingConfig($license, ['pool_size' => 2]);

        $this->disableFloatingLicenses();

        $admin = User::factory()->superuser()->create();
        $target = User::factory()->create();

        $this->actingAs($admin)
            ->post(route('licenses.checkout.save', $license->id), [
                'assigned_to' => $target->id,
            ])
            ->assertSessionHas('success');

        $this->assertDatabaseHas('license_seats', [
            'license_id' => $license->id,
            'assigned_to' => $target->id,
        ]);
        $this->assertEquals(0, FloatingLicenseAllocation::count(),
            'Master switch off means the core seat flow runs even when a config exists');
    }
}
