<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\License;
use App\Models\User;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class PermissionsTest extends TestCase
{
    public function test_index_requires_view_permission()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('floating-licenses.index'))
            ->assertForbidden();
    }

    public function test_show_requires_view_permission()
    {
        $config = $this->createFloatingConfig();

        $this->actingAs(User::factory()->create())
            ->get(route('floating-licenses.show', $config))
            ->assertForbidden();
    }

    public function test_create_requires_manage_permission()
    {
        $viewer = $this->createUserWithFloatingPermissions(['floating_licenses.view']);

        $this->actingAs($viewer)
            ->get(route('floating-licenses.create'))
            ->assertForbidden();
    }

    public function test_update_requires_manage_permission()
    {
        $config = $this->createFloatingConfig();
        $viewer = $this->createUserWithFloatingPermissions(['floating_licenses.view']);

        $this->actingAs($viewer)
            ->put(route('floating-licenses.update', $config), [
                'pool_size' => 10,
                'cost_mode' => 'pool_slot',
                'lease_duration_minutes' => 120,
                'idle_timeout_minutes' => 60,
            ])
            ->assertForbidden();
    }

    public function test_web_allocate_requires_allocate_permission()
    {
        $config = $this->createFloatingConfig();
        $viewer = $this->createUserWithFloatingPermissions(['floating_licenses.view']);

        $this->actingAs($viewer)
            ->post(route('floating-licenses.allocate', $config), [
                'user_id' => $viewer->id,
            ])
            ->assertForbidden();
    }

    public function test_user_can_release_their_own_allocation_with_allocate_permission()
    {
        $config = $this->createFloatingConfig();
        $user = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $allocation = app(FloatingLicenseService::class)->allocate($config, $user);

        $this->actingAs($user)
            ->post(route('floating-licenses.allocations.release', $allocation))
            ->assertRedirect(route('floating-licenses.show', $config));

        $this->assertEquals('released', $allocation->refresh()->status);
    }

    public function test_user_cannot_release_another_users_allocation_without_release_permission()
    {
        $config = $this->createFloatingConfig();
        $owner = User::factory()->create();
        $other = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $allocation = app(FloatingLicenseService::class)->allocate($config, $owner);

        $this->actingAs($other)
            ->post(route('floating-licenses.allocations.release', $allocation))
            ->assertForbidden();

        $this->assertTrue($allocation->refresh()->isActive());
    }

    public function test_user_with_release_permission_can_release_another_users_allocation()
    {
        $config = $this->createFloatingConfig();
        $owner = User::factory()->create();
        $releaser = $this->createUserWithFloatingPermissions(['floating_licenses.release']);
        $allocation = app(FloatingLicenseService::class)->allocate($config, $owner);

        $this->actingAs($releaser)
            ->post(route('floating-licenses.allocations.release', $allocation))
            ->assertRedirect();

        $this->assertEquals('released', $allocation->refresh()->status);
    }

    public function test_api_endpoints_require_authentication()
    {
        $config = $this->createFloatingConfig();
        $license = License::find($config->license_id);
        $allocation = app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

        $this->postJson(route('api.floating-licenses.allocate', $license), ['user_id' => 1])
            ->assertUnauthorized();
        $this->getJson(route('api.floating-licenses.availability', $license))
            ->assertUnauthorized();
        $this->postJson(route('api.floating-license-allocations.heartbeat', $allocation))
            ->assertUnauthorized();
        $this->postJson(route('api.floating-license-allocations.release', $allocation))
            ->assertUnauthorized();
    }
}
