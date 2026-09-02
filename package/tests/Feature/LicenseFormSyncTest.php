<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\Category;
use App\Models\License;
use App\Models\User;
use Illuminate\Http\Request;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Support\FloatingLicenseSync;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class LicenseFormSyncTest extends TestCase
{
    public function test_sync_creates_config_from_license_attributes()
    {
        $license = License::factory()->create(['seats' => 17, 'purchase_cost' => 3500]);

        FloatingLicenseSync::syncFromRequest($license, Request::create('', 'POST', [
            'floating_enabled' => '1',
            'floating_cost_mode' => 'active_user',
            'floating_allow_over_allocation' => '1',
        ]));

        $config = FloatingLicenseConfig::where('license_id', $license->id)->first();

        $this->assertNotNull($config);
        $this->assertEquals(17, $config->pool_size, 'Pool size must come from licenses.seats');
        $this->assertEquals(3500.0, (float) $config->total_cost, 'Total cost must come from licenses.purchase_cost');
        $this->assertEquals(FloatingLicenseConfig::COST_MODE_ACTIVE_USER, $config->cost_mode);
        $this->assertTrue($config->allow_over_allocation);
        $this->assertNull($config->lease_duration_minutes, 'Durations default to null');
        $this->assertNull($config->idle_timeout_minutes, 'Durations default to null');
    }

    public function test_sync_defaults_over_allocation_to_true_for_new_configs()
    {
        $license = License::factory()->create(['seats' => 5]);

        FloatingLicenseSync::syncFromRequest($license, Request::create('', 'POST', [
            'floating_enabled' => '1',
        ]));

        $config = FloatingLicenseConfig::where('license_id', $license->id)->first();

        $this->assertNotNull($config);
        $this->assertTrue($config->allow_over_allocation);
    }

    public function test_sync_keeps_existing_config_in_sync_with_license_attributes()
    {
        $license = License::factory()->create(['seats' => 5, 'purchase_cost' => 100]);
        $config = $this->createFloatingConfig($license, ['cost_mode' => FloatingLicenseConfig::COST_MODE_ACTIVE_USER]);

        $license->seats = 42;
        $license->purchase_cost = 999;
        $license->save();

        FloatingLicenseSync::syncFromRequest($license->refresh(), Request::create('', 'POST', [
            'floating_enabled' => '1',
            'floating_allow_over_allocation' => '0',
        ]));

        $config->refresh();
        $this->assertEquals(42, $config->pool_size);
        $this->assertEquals(999.0, (float) $config->total_cost);
        $this->assertEquals(FloatingLicenseConfig::COST_MODE_ACTIVE_USER, $config->cost_mode, 'Stored cost mode survives when the request omits it');
        $this->assertFalse($config->allow_over_allocation);
    }

    public function test_sync_is_noop_when_master_switch_off()
    {
        $this->disableFloatingLicenses();

        $license = License::factory()->create(['seats' => 5]);

        FloatingLicenseSync::syncFromRequest($license, Request::create('', 'POST', [
            'floating_enabled' => '1',
        ]));

        $this->assertEquals(0, FloatingLicenseConfig::where('license_id', $license->id)->count());
    }

    public function test_disabling_soft_deletes_config_without_active_allocations()
    {
        $license = License::factory()->create();
        $config = $this->createFloatingConfig($license);

        FloatingLicenseSync::syncFromRequest($license, Request::create('', 'POST', []));

        $this->assertSoftDeleted('floating_license_configs', ['id' => $config->id]);
    }

    public function test_disabling_with_active_allocations_keeps_config()
    {
        $license = License::factory()->create();
        $config = $this->createFloatingConfig($license);
        app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

        FloatingLicenseSync::syncFromRequest($license, Request::create('', 'POST', []));

        $this->assertNull($config->refresh()->deleted_at, 'Config with active allocations must be kept');
        $this->assertEquals(
            trans('floating-licenses::floating.message.kept_active_allocations'),
            session('warning')
        );
    }

    public function test_license_store_http_creates_floating_config()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('licenses.store'), [
                'name' => 'Enscape Floating',
                'seats' => '17',
                'category_id' => Category::factory()->forLicenses()->create()->id,
                'purchase_cost' => '3500.00',
                'floating_enabled' => '1',
                'floating_cost_mode' => 'active_user',
                'floating_allow_over_allocation' => '1',
            ])
            ->assertStatus(302);

        $license = License::where('name', 'Enscape Floating')->sole();
        $config = FloatingLicenseConfig::where('license_id', $license->id)->first();

        $this->assertNotNull($config);
        $this->assertEquals(17, $config->pool_size);
        $this->assertEquals(3500.0, (float) $config->total_cost);
        $this->assertEquals(FloatingLicenseConfig::COST_MODE_ACTIVE_USER, $config->cost_mode);
        $this->assertTrue($config->allow_over_allocation);
    }

    public function test_license_store_http_without_floating_enabled_creates_no_config()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->post(route('licenses.store'), [
                'name' => 'Plain License',
                'seats' => '3',
                'category_id' => Category::factory()->forLicenses()->create()->id,
            ])
            ->assertStatus(302);

        $license = License::where('name', 'Plain License')->sole();

        $this->assertEquals(0, FloatingLicenseConfig::where('license_id', $license->id)->count());
    }

    public function test_license_update_http_disables_floating_when_unchecked()
    {
        $license = License::factory()->create(['seats' => 5]);
        $config = $this->createFloatingConfig($license);

        $this->actingAs(User::factory()->superuser()->create())
            ->put(route('licenses.update', $license), [
                'name' => $license->name,
                'seats' => '5',
                'category_id' => $license->category_id,
            ])
            ->assertStatus(302);

        $this->assertSoftDeleted('floating_license_configs', ['id' => $config->id]);
    }

    public function test_web_routes_forbidden_when_master_switch_off()
    {
        $this->disableFloatingLicenses();
        $config = $this->createFloatingConfig();

        $admin = User::factory()->superuser()->create();

        $this->actingAs($admin)->get(route('floating-licenses.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('floating-licenses.show', $config))->assertForbidden();
        $this->actingAs($admin)->get(route('floating-licenses.create'))->assertForbidden();
    }

    public function test_api_routes_forbidden_when_master_switch_off()
    {
        $this->disableFloatingLicenses();
        $config = $this->createFloatingConfig();
        $apiUser = $this->createUserWithFloatingPermissions(['floating_licenses.view']);

        $this->actingAsForApi($apiUser)
            ->getJson(route('api.floating-licenses.availability', $config->license_id))
            ->assertForbidden();
    }

    public function test_bulk_routes_forbidden_when_master_switch_off()
    {
        $this->disableFloatingLicenses();
        $license = License::factory()->create();
        $admin = User::factory()->superuser()->create();

        $this->actingAs($admin)
            ->post(route('floating-licenses.license.bulk-add', $license), ['user_ids' => [1]])
            ->assertForbidden();
        $this->actingAs($admin)
            ->post(route('floating-licenses.license.bulk-remove', $license), ['user_ids' => [1]])
            ->assertForbidden();
    }

    public function test_config_resolver_returns_null_when_master_off_and_no_config()
    {
        $this->disableFloatingLicenses();
        $license = License::factory()->create(['seats' => 5]);

        $this->assertNull(FloatingLicenseSync::configForLicense($license));
        $this->assertEquals(0, FloatingLicenseConfig::where('license_id', $license->id)->count(),
            'The resolver must not persist anything while the master switch is off');
    }

    public function test_config_resolver_lazily_creates_and_persists_default_config_when_master_on()
    {
        $license = License::factory()->create(['seats' => 17, 'purchase_cost' => 3500]);

        $config = FloatingLicenseSync::configForLicense($license);

        $this->assertNotNull($config);
        $this->assertTrue($config->exists, 'The default config must be persisted');
        $this->assertEquals(17, $config->pool_size);
        $this->assertEquals(3500.0, (float) $config->total_cost);
        $this->assertEquals(FloatingLicenseConfig::COST_MODE_ACTIVE_USER, $config->cost_mode);
        $this->assertTrue($config->allow_over_allocation);
        $this->assertNull($config->lease_duration_minutes);
        $this->assertNull($config->idle_timeout_minutes);

        $this->assertTrue(
            FloatingLicenseSync::configForLicense($license)->is($config),
            'A second resolution must return the persisted config, not create another'
        );
    }

    public function test_config_resolver_returns_existing_config_unchanged()
    {
        $license = License::factory()->create(['seats' => 99, 'purchase_cost' => 1]);
        $config = $this->createFloatingConfig($license, [
            'pool_size' => 3,
            'total_cost' => 42,
            'cost_mode' => FloatingLicenseConfig::COST_MODE_POOL_SLOT,
            'allow_over_allocation' => false,
        ]);

        $resolved = FloatingLicenseSync::configForLicense($license);

        $this->assertTrue($resolved->is($config));
        $this->assertEquals(3, $resolved->pool_size);
        $this->assertEquals(FloatingLicenseConfig::COST_MODE_POOL_SLOT, $resolved->cost_mode);
        $this->assertFalse($resolved->allow_over_allocation);
    }
}
