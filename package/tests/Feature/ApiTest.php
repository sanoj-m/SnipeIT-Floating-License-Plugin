<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\License;
use App\Models\User;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class ApiTest extends TestCase
{
    public function test_api_allocate_creates_allocation_and_uses_standard_envelope()
    {
        $config = $this->createFloatingConfig();
        $apiUser = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $target = User::factory()->create();

        $response = $this->actingAsForApi($apiUser)
            ->postJson(route('api.floating-licenses.allocate', $config->license_id), [
                'user_id' => $target->id,
                'notes' => 'via api',
            ])
            ->assertOk()
            ->assertJsonStructure(['status', 'messages', 'payload']);

        $this->assertEquals('success', $response->json('status'));

        $this->assertDatabaseHas('floating_license_allocations', [
            'license_id' => $config->license_id,
            'user_id' => $target->id,
            'status' => 'active',
        ]);
    }

    public function test_api_allocate_validates_user_id()
    {
        $config = $this->createFloatingConfig();
        $apiUser = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);

        $this->actingAsForApi($apiUser)
            ->postJson(route('api.floating-licenses.allocate', $config->license_id), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user_id']);
    }

    public function test_api_allocate_returns_error_envelope_when_pool_exhausted()
    {
        $config = $this->createFloatingConfig(null, ['pool_size' => 1]);
        $service = app(FloatingLicenseService::class);
        $service->allocate($config, User::factory()->create());

        $apiUser = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);

        $this->actingAsForApi($apiUser)
            ->postJson(route('api.floating-licenses.allocate', $config->license_id), [
                'user_id' => User::factory()->create()->id,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['status', 'messages', 'payload'])
            ->assertJson(['status' => 'error']);
    }

    public function test_api_allocate_on_license_without_floating_config_returns_404()
    {
        $license = License::factory()->create();
        $apiUser = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);

        $this->actingAsForApi($apiUser)
            ->postJson(route('api.floating-licenses.allocate', $license->id), [
                'user_id' => User::factory()->create()->id,
            ])
            ->assertNotFound();
    }

    public function test_api_availability_returns_pool_stats_in_standard_envelope()
    {
        $config = $this->createFloatingConfig(null, ['pool_size' => 4]);
        app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

        $apiUser = $this->createUserWithFloatingPermissions(['floating_licenses.view']);

        $this->actingAsForApi($apiUser)
            ->getJson(route('api.floating-licenses.availability', $config->license_id))
            ->assertOk()
            ->assertJsonStructure(['status', 'messages', 'payload'])
            ->assertJson([
                'status' => 'success',
                'payload' => [
                    'pool_size' => 4,
                    'active' => 1,
                    'available' => 3,
                    'over_allocation_allowed' => false,
                ],
            ]);
    }

    public function test_api_availability_requires_view_permission()
    {
        $config = $this->createFloatingConfig();

        $this->actingAsForApi(User::factory()->create())
            ->getJson(route('api.floating-licenses.availability', $config->license_id))
            ->assertForbidden();
    }

    public function test_api_heartbeat_extends_lease_and_uses_standard_envelope()
    {
        $config = $this->createFloatingConfig();
        $user = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $allocation = app(FloatingLicenseService::class)->allocate($config, $user);

        $this->actingAsForApi($user)
            ->postJson(route('api.floating-license-allocations.heartbeat', $allocation))
            ->assertOk()
            ->assertJsonStructure(['status', 'messages', 'payload'])
            ->assertJson(['status' => 'success']);
    }

    public function test_api_heartbeat_fails_on_non_active_allocation()
    {
        $config = $this->createFloatingConfig();
        $service = app(FloatingLicenseService::class);
        $user = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $allocation = $service->allocate($config, $user);
        $service->release($allocation, $user);

        $this->actingAsForApi($user)
            ->postJson(route('api.floating-license-allocations.heartbeat', $allocation->refresh()))
            ->assertStatus(422)
            ->assertJson(['status' => 'error']);
    }

    public function test_api_release_releases_own_allocation()
    {
        $config = $this->createFloatingConfig();
        $user = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $allocation = app(FloatingLicenseService::class)->allocate($config, $user);

        $this->actingAsForApi($user)
            ->postJson(route('api.floating-license-allocations.release', $allocation))
            ->assertOk()
            ->assertJsonStructure(['status', 'messages', 'payload'])
            ->assertJson(['status' => 'success']);

        $this->assertEquals(
            FloatingLicenseAllocation::STATUS_RELEASED,
            $allocation->refresh()->status
        );
    }

    public function test_api_release_of_another_users_allocation_requires_release_permission()
    {
        $config = $this->createFloatingConfig();
        $owner = User::factory()->create();
        $other = $this->createUserWithFloatingPermissions(['floating_licenses.allocate']);
        $allocation = app(FloatingLicenseService::class)->allocate($config, $owner);

        $this->actingAsForApi($other)
            ->postJson(route('api.floating-license-allocations.release', $allocation))
            ->assertForbidden();

        $this->assertTrue($allocation->refresh()->isActive());
    }
}
