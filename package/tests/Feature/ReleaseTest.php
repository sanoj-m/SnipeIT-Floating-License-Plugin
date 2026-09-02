<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\License;
use App\Models\User;
use SnipeIt\FloatingLicenses\Exceptions\InvalidAllocationException;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class ReleaseTest extends TestCase
{
    public function test_release_marks_allocation_released_and_restores_availability()
    {
        $config = $this->createFloatingConfig(null, ['pool_size' => 2]);
        $service = app(FloatingLicenseService::class);
        $user = User::factory()->create();

        $allocation = $service->allocate($config, $user);

        $released = $service->release($allocation, $user);

        $this->assertEquals(FloatingLicenseAllocation::STATUS_RELEASED, $released->status);
        $this->assertNotNull($released->released_at);
        $this->assertFalse($released->isActive());

        $availability = $service->availability($config->refresh());
        $this->assertEquals(0, $availability['active']);
        $this->assertEquals(2, $availability['available']);
    }

    public function test_release_writes_audit_log()
    {
        $config = $this->createFloatingConfig();
        $service = app(FloatingLicenseService::class);
        $user = User::factory()->create();

        $allocation = $service->allocate($config, $user);
        $service->release($allocation, $user);

        $this->assertDatabaseHas('action_logs', [
            'item_type' => License::class,
            'item_id' => $config->license_id,
            'target_id' => $user->id,
            'action_type' => 'floating.release',
        ]);
    }

    public function test_release_recalculates_costs_in_active_user_mode()
    {
        $config = $this->createFloatingConfig(null, [
            'cost_mode' => FloatingLicenseConfig::COST_MODE_ACTIVE_USER,
            'total_cost' => 1000,
        ]);
        $service = app(FloatingLicenseService::class);

        $first = $service->allocate($config, User::factory()->create());
        $second = $service->allocate($config, User::factory()->create());

        $this->assertEquals(500.0, (float) $first->refresh()->allocated_cost);
        $this->assertEquals(500.0, (float) $second->refresh()->allocated_cost);

        $service->release($second, $second->user);

        $this->assertEquals(1000.0, (float) $first->refresh()->allocated_cost,
            'Remaining active allocation should absorb the full cost after a release');
    }

    public function test_releasing_a_non_active_allocation_throws()
    {
        $config = $this->createFloatingConfig();
        $service = app(FloatingLicenseService::class);

        $allocation = $service->allocate($config, User::factory()->create());
        $service->release($allocation);

        $this->expectException(InvalidAllocationException::class);
        $service->release($allocation->refresh());
    }
}
