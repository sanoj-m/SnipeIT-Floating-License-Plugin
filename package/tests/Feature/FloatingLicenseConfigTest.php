<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\License;
use App\Models\User;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class FloatingLicenseConfigTest extends TestCase
{
    public function test_floating_config_can_be_enabled_on_existing_license()
    {
        $license = License::factory()->create();

        $config = FloatingLicenseConfig::create([
            'license_id' => $license->id,
            'pool_size' => 10,
            'total_cost' => 5000,
            'cost_mode' => FloatingLicenseConfig::COST_MODE_POOL_SLOT,
            'allow_over_allocation' => false,
            'lease_duration_minutes' => 120,
            'idle_timeout_minutes' => 60,
        ]);

        $this->assertDatabaseHas('floating_license_configs', [
            'id' => $config->id,
            'license_id' => $license->id,
            'pool_size' => 10,
            'cost_mode' => 'pool_slot',
        ]);
        $this->assertTrue($config->license->is($license));
    }

    public function test_enabling_floating_via_web_stores_config_and_redirects()
    {
        $license = License::factory()->create();
        $manager = $this->createUserWithFloatingPermissions(['floating_licenses.manage']);

        $response = $this->actingAs($manager)
            ->post(route('floating-licenses.store', $license), [
                'pool_size' => 8,
                'total_cost' => 2400,
                'cost_mode' => 'active_user',
                'allow_over_allocation' => '1',
                'lease_duration_minutes' => 90,
                'idle_timeout_minutes' => 45,
            ]);

        $config = FloatingLicenseConfig::where('license_id', $license->id)->first();

        $this->assertNotNull($config);
        $this->assertEquals(8, $config->pool_size);
        $this->assertEquals('active_user', $config->cost_mode);
        $this->assertTrue($config->allow_over_allocation);
        $this->assertEquals(90, $config->lease_duration_minutes);
        $this->assertEquals(45, $config->idle_timeout_minutes);

        $response->assertRedirect(route('floating-licenses.show', $config));
    }

    public function test_enabling_floating_via_web_requires_manage_permission()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('floating-licenses.store', License::factory()->create()), [
                'pool_size' => 8,
            ])
            ->assertForbidden();

        $this->assertEquals(0, FloatingLicenseConfig::count());
    }

    public function test_store_validates_pool_size()
    {
        $manager = $this->createUserWithFloatingPermissions(['floating_licenses.manage']);

        $this->actingAs($manager)
            ->post(route('floating-licenses.store', License::factory()->create()), [
                'pool_size' => 0,
            ])
            ->assertSessionHasErrors('pool_size');
    }

    public function test_index_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('floating-licenses.index'))
            ->assertOk();
    }

    public function test_show_page_renders()
    {
        $config = $this->createFloatingConfig();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('floating-licenses.show', $config))
            ->assertOk();
    }

    public function test_create_page_renders()
    {
        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('floating-licenses.create'))
            ->assertOk();
    }

    public function test_edit_page_renders()
    {
        $config = $this->createFloatingConfig();

        $this->actingAs(User::factory()->superuser()->create())
            ->get(route('floating-licenses.edit', $config))
            ->assertOk();
    }

    public function test_config_can_be_disabled_when_no_active_allocations()
    {
        $config = $this->createFloatingConfig();
        $manager = $this->createUserWithFloatingPermissions(['floating_licenses.manage']);

        $this->actingAs($manager)
            ->delete(route('floating-licenses.destroy', $config))
            ->assertRedirect(route('floating-licenses.index'));

        $this->assertSoftDeleted('floating_license_configs', ['id' => $config->id]);
    }
}
