<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\License;
use App\Models\User;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class LicenseViewIntegrationTest extends TestCase
{
    public function test_license_view_shows_floating_info_rows_and_bulk_dropdown_when_master_on()
    {
        $license = License::factory()->create(['seats' => 17, 'purchase_cost' => 3500]);
        $this->createFloatingConfig($license, ['pool_size' => 17, 'total_cost' => 3500]);
        $admin = User::factory()->superuser()->create();

        $this->actingAs($admin)
            ->get(route('licenses.show', $license))
            ->assertOk()
            ->assertSee(trans('floating-licenses::floating.license_type'))
            ->assertSee(trans('floating-licenses::floating.type_floating'))
            ->assertSee(trans('floating-licenses::floating.pool_size'))
            ->assertSee(trans('floating-licenses::floating.assigned_users'))
            ->assertSee(trans('floating-licenses::floating.cost_per_user'))
            ->assertSee(route('floating-licenses.license.bulk-add.form', $license))
            ->assertSee(route('floating-licenses.license.bulk-remove.form', $license));
    }

    public function test_license_view_shows_floating_assigned_users_and_over_allocation_indicator()
    {
        $license = License::factory()->create(['seats' => 1]);
        $config = $this->createFloatingConfig($license, [
            'pool_size' => 1,
            'allow_over_allocation' => true,
        ]);
        $admin = User::factory()->superuser()->create();

        $service = app(FloatingLicenseService::class);
        $service->allocate($config, User::factory()->create());
        $service->allocate($config, User::factory()->create());

        $this->actingAs($admin)
            ->get(route('licenses.show', $license))
            ->assertOk()
            ->assertSee(trans('floating-licenses::floating.floating_assignments_heading'))
            ->assertSee(trans('floating-licenses::floating.over_allocated_label', [
                'assigned' => 2,
                'pool' => 1,
                'excess' => 1,
            ]));
    }

    public function test_license_view_hides_floating_ui_when_master_off()
    {
        $license = License::factory()->create();
        $this->createFloatingConfig($license);
        $admin = User::factory()->superuser()->create();

        $this->disableFloatingLicenses();

        $this->actingAs($admin)
            ->get(route('licenses.show', $license))
            ->assertOk()
            ->assertDontSee(trans('floating-licenses::floating.license_type'))
            ->assertDontSee('floating-licenses/license/'.$license->id.'/bulk-add', false)
            ->assertDontSee('floating-licenses/license/'.$license->id.'/bulk-remove', false);
    }

    public function test_license_view_hides_bulk_dropdown_and_costs_without_permissions()
    {
        $license = License::factory()->create(['purchase_cost' => 500]);
        $this->createFloatingConfig($license, ['total_cost' => 500]);

        // Can view licenses, but holds no floating_licenses.* permissions.
        $viewer = User::factory()->create(['permissions' => json_encode(['licenses.view' => '1'])]);

        $this->actingAs($viewer)
            ->get(route('licenses.show', $license))
            ->assertOk()
            ->assertSee(trans('floating-licenses::floating.license_type'))
            ->assertDontSee(trans('floating-licenses::floating.cost_per_user'))
            ->assertDontSee('floating-licenses/license/'.$license->id.'/bulk-add', false)
            ->assertDontSee('floating-licenses/license/'.$license->id.'/bulk-remove', false);
    }

    public function test_license_view_marks_plain_license_as_fixed_seats_type_when_master_on()
    {
        $license = License::factory()->create(['seats' => 5]);
        $admin = User::factory()->superuser()->create();

        // No explicit config exists yet: the type row still renders (the
        // resolver lazily creates the default pool), labelled Fixed Seats.
        $this->assertEquals(0, FloatingLicenseConfig::where('license_id', $license->id)->count());

        $this->actingAs($admin)
            ->get(route('licenses.show', $license))
            ->assertOk()
            ->assertSee(trans('floating-licenses::floating.type_fixed'));

        $this->assertEquals(1, FloatingLicenseConfig::where('license_id', $license->id)->count(),
            'Viewing a license under master-on lazily persists its default floating config');
    }

    public function test_license_view_overrides_remaining_row_with_floating_availability()
    {
        $license = License::factory()->create(['seats' => 35]);
        $config = $this->createFloatingConfig($license, ['pool_size' => 35, 'allow_over_allocation' => true]);
        $admin = User::factory()->superuser()->create();

        $service = app(FloatingLicenseService::class);
        foreach (range(1, 46) as $i) {
            $service->allocate($config, User::factory()->create());
        }

        // The core "Remaining" info row is replaced client-side with the
        // floating availability: 35 - 46 = -11 (over-allocated).
        $this->actingAs($admin)
            ->get(route('licenses.show', $license))
            ->assertOk()
            ->assertSee('id="floating-remaining-override" data-remaining="-11"', false);
    }

    public function test_license_view_shows_available_tab_note_for_floating_licenses()
    {
        $license = License::factory()->create();
        $this->createFloatingConfig($license);
        $admin = User::factory()->superuser()->create();

        $this->actingAs($admin)
            ->get(route('licenses.show', $license))
            ->assertOk()
            ->assertSee(trans('floating-licenses::floating.available_tab_note'));
    }

    public function test_license_view_has_no_remaining_override_or_note_when_master_off()
    {
        $license = License::factory()->create();
        $this->createFloatingConfig($license);
        $admin = User::factory()->superuser()->create();

        $this->disableFloatingLicenses();

        $this->actingAs($admin)
            ->get(route('licenses.show', $license))
            ->assertOk()
            ->assertDontSee('floating-remaining-override', false)
            ->assertDontSee(trans('floating-licenses::floating.available_tab_note'));
    }
}
