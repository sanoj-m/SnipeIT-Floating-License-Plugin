<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\User;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class CostCalculationTest extends TestCase
{
    public function test_pool_slot_mode_charges_fixed_per_slot_cost()
    {
        // $10,000 pool with 10 slots => $1,000 per slot.
        $config = $this->createFloatingConfig(null, [
            'cost_mode' => FloatingLicenseConfig::COST_MODE_POOL_SLOT,
            'total_cost' => 10000,
            'pool_size' => 10,
        ]);
        $service = app(FloatingLicenseService::class);

        $this->assertEquals(1000.0, $service->costPerSlot($config));

        $first = $service->allocate($config, User::factory()->create());
        $second = $service->allocate($config, User::factory()->create());

        $this->assertEquals(1000.0, (float) $first->refresh()->allocated_cost);
        $this->assertEquals(1000.0, (float) $second->refresh()->allocated_cost,
            'pool_slot cost must not change as more allocations become active');
    }

    public function test_active_user_mode_splits_cost_across_active_allocations()
    {
        // $10,000 pool, cost divided by number of active users.
        $config = $this->createFloatingConfig(null, [
            'cost_mode' => FloatingLicenseConfig::COST_MODE_ACTIVE_USER,
            'total_cost' => 10000,
            'pool_size' => 10,
        ]);
        $service = app(FloatingLicenseService::class);

        $first = $service->allocate($config, User::factory()->create());
        $this->assertEquals(10000.0, (float) $first->refresh()->allocated_cost);

        $second = $service->allocate($config, User::factory()->create());
        $this->assertEquals(5000.0, (float) $first->refresh()->allocated_cost,
            'Existing allocation should be recalculated when a second user allocates');
        $this->assertEquals(5000.0, (float) $second->refresh()->allocated_cost);
    }

    public function test_zero_total_cost_yields_zero_allocated_cost()
    {
        $config = $this->createFloatingConfig(null, [
            'total_cost' => 0,
            'pool_size' => 5,
        ]);
        $service = app(FloatingLicenseService::class);

        $this->assertEquals(0.0, $service->costPerSlot($config));

        $allocation = $service->allocate($config, User::factory()->create());
        $this->assertEquals(0.0, (float) $allocation->refresh()->allocated_cost);
    }

    public function test_active_user_mode_spreads_cost_across_over_allocated_pool()
    {
        // The Enscape use case: a 2-slot pool shared by 3 users.
        $config = $this->createFloatingConfig(null, [
            'cost_mode' => FloatingLicenseConfig::COST_MODE_ACTIVE_USER,
            'total_cost' => 300,
            'pool_size' => 2,
            'allow_over_allocation' => true,
        ]);
        $service = app(FloatingLicenseService::class);

        $allocations = [
            $service->allocate($config, User::factory()->create()),
            $service->allocate($config, User::factory()->create()),
            $service->allocate($config, User::factory()->create()),
        ];

        foreach ($allocations as $allocation) {
            $this->assertTrue($allocation->refresh()->isActive());
            $this->assertEquals(100.0, (float) $allocation->allocated_cost,
                'active_user cost must be total_cost / active allocations');
        }
    }

    public function test_cost_per_user_matches_the_live_active_user_spread()
    {
        $config = $this->createFloatingConfig(null, [
            'cost_mode' => FloatingLicenseConfig::COST_MODE_ACTIVE_USER,
            'total_cost' => 300,
            'pool_size' => 2,
            'allow_over_allocation' => true,
        ]);
        $service = app(FloatingLicenseService::class);

        $this->assertEquals(0.0, $service->costPerUser($config), 'No active users means no per-user cost');

        $service->allocate($config, User::factory()->create());
        $this->assertEquals(300.0, $service->costPerUser($config));

        $service->allocate($config, User::factory()->create());
        $service->allocate($config, User::factory()->create());
        $this->assertEquals(100.0, $service->costPerUser($config));
    }

    public function test_cost_per_user_in_pool_slot_mode_is_the_per_slot_price()
    {
        $config = $this->createFloatingConfig(null, [
            'cost_mode' => FloatingLicenseConfig::COST_MODE_POOL_SLOT,
            'total_cost' => 1000,
            'pool_size' => 10,
        ]);
        $service = app(FloatingLicenseService::class);

        $service->allocate($config, User::factory()->create());

        $this->assertEquals(100.0, $service->costPerUser($config));
    }

    public function test_availability_reports_over_allocation_independently_of_allow_flag()
    {
        $config = $this->createFloatingConfig(null, [
            'pool_size' => 2,
            'allow_over_allocation' => true,
        ]);
        $service = app(FloatingLicenseService::class);

        $stats = $service->availability($config);
        $this->assertFalse($stats['over_allocated']);
        $this->assertEquals(0, $stats['excess']);

        foreach (range(1, 3) as $i) {
            $service->allocate($config, User::factory()->create());
        }

        $stats = $service->availability($config->refresh());
        $this->assertTrue($stats['over_allocated']);
        $this->assertEquals(1, $stats['excess']);
        $this->assertEquals(3, $stats['active']);
        $this->assertEquals(2, $stats['pool_size']);
    }
}
