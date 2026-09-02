<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\Actionlog;
use App\Models\License;
use App\Models\User;
use Carbon\Carbon;
use SnipeIt\FloatingLicenses\Exceptions\PoolExhaustedException;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class AllocationTest extends TestCase
{
    public function test_allocate_creates_active_allocation_and_decrements_availability()
    {
        Carbon::setTestNow(Carbon::create(2025, 6, 1, 12, 0, 0));

        try {
            $config = $this->createFloatingConfig(null, [
                'pool_size' => 5,
                'lease_duration_minutes' => 120,
            ]);
            $user = User::factory()->create();

            $allocation = app(FloatingLicenseService::class)->allocate($config, $user);

            $this->assertTrue($allocation->isActive());
            $this->assertEquals($config->license_id, $allocation->license_id);
            $this->assertEquals($user->id, $allocation->user_id);
            $this->assertEquals(Carbon::now()->toDateTimeString(), $allocation->allocated_at->toDateTimeString());
            $this->assertEquals(
                Carbon::now()->addMinutes(120)->toDateTimeString(),
                $allocation->expires_at->toDateTimeString(),
                'expires_at should be set from the pool lease duration'
            );

            $availability = app(FloatingLicenseService::class)->availability($config->refresh());
            $this->assertEquals(5, $availability['pool_size']);
            $this->assertEquals(1, $availability['active']);
            $this->assertEquals(4, $availability['available']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_allocate_writes_audit_log()
    {
        $config = $this->createFloatingConfig();
        $user = User::factory()->create();

        $allocation = app(FloatingLicenseService::class)->allocate($config, $user);

        $this->assertDatabaseHas('action_logs', [
            'item_type' => License::class,
            'item_id' => $config->license_id,
            'target_type' => User::class,
            'target_id' => $user->id,
            'action_type' => 'floating.allocate',
            'note' => trans('floating-licenses::floating.log.allocate', ['id' => $allocation->id]),
        ]);
    }

    public function test_full_pool_throws_and_writes_capacity_exceeded_audit()
    {
        $config = $this->createFloatingConfig(null, ['pool_size' => 1]);
        $service = app(FloatingLicenseService::class);

        $service->allocate($config, User::factory()->create());

        $availability = $service->availability($config->refresh());
        $this->assertEquals(0, $availability['available']);

        try {
            $service->allocate($config, User::factory()->create());
            $this->fail('PoolExhaustedException was not thrown for a full pool');
        } catch (PoolExhaustedException $e) {
            $this->assertTrue($e->config->is($config));
        }

        $this->assertDatabaseHas('action_logs', [
            'item_type' => License::class,
            'item_id' => $config->license_id,
            'action_type' => 'floating.capacity_exceeded',
        ]);

        $this->assertEquals(
            1,
            FloatingLicenseAllocation::where('license_id', $config->license_id)->active()->count()
        );
    }

    public function test_over_allocation_permits_exceeding_pool_size()
    {
        $config = $this->createFloatingConfig(null, [
            'pool_size' => 1,
            'allow_over_allocation' => true,
        ]);
        $service = app(FloatingLicenseService::class);

        $service->allocate($config, User::factory()->create());
        $second = $service->allocate($config, User::factory()->create());

        $this->assertTrue($second->isActive());
        $this->assertEquals(
            2,
            FloatingLicenseAllocation::where('license_id', $config->license_id)->active()->count()
        );
    }

    /**
     * Two allocations against a pool_size=1 pool requested in quick
     * succession: only one may succeed. The service re-reads the config with
     * lockForUpdate() inside a transaction, so this also holds under true
     * concurrency; this test documents the DB-level outcome of the race.
     */
    public function test_concurrent_allocation_race_allows_only_one_active_allocation()
    {
        $config = $this->createFloatingConfig(null, ['pool_size' => 1]);
        $service = app(FloatingLicenseService::class);

        $results = [];
        foreach ([User::factory()->create(), User::factory()->create()] as $user) {
            try {
                $results[] = $service->allocate($config, $user);
            } catch (PoolExhaustedException) {
                $results[] = null;
            }
        }

        $successful = array_filter($results);
        $this->assertCount(1, $successful, 'Exactly one of the two simultaneous allocations should succeed');
        $this->assertEquals(
            1,
            FloatingLicenseAllocation::where('license_id', $config->license_id)->active()->count()
        );
    }

    public function test_allocation_can_optionally_reference_an_asset()
    {
        $config = $this->createFloatingConfig();
        $user = User::factory()->create();
        $asset = \App\Models\Asset::factory()->create();

        $allocation = app(FloatingLicenseService::class)->allocate($config, $user, $asset, 'via test');

        $this->assertEquals($asset->id, $allocation->asset_id);
        $this->assertEquals('via test', $allocation->notes);
    }
}
