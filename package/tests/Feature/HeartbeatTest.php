<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use SnipeIt\FloatingLicenses\Exceptions\InvalidAllocationException;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class HeartbeatTest extends TestCase
{
    public function test_heartbeat_extends_last_seen_and_expiry()
    {
        Carbon::setTestNow(Carbon::create(2025, 6, 1, 12, 0, 0));

        try {
            $config = $this->createFloatingConfig(null, ['lease_duration_minutes' => 120]);
            $service = app(FloatingLicenseService::class);
            $allocation = $service->allocate($config, User::factory()->create());

            Carbon::setTestNow(Carbon::create(2025, 6, 1, 12, 45, 0));

            $service->heartbeat($allocation);

            $allocation->refresh();
            $this->assertEquals(Carbon::now()->toDateTimeString(), $allocation->last_seen_at->toDateTimeString());
            $this->assertEquals(
                Carbon::now()->addMinutes(120)->toDateTimeString(),
                $allocation->expires_at->toDateTimeString(),
                'Heartbeat should push expires_at out by the full lease duration'
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_heartbeat_on_released_allocation_throws()
    {
        $config = $this->createFloatingConfig();
        $service = app(FloatingLicenseService::class);
        $allocation = $service->allocate($config, User::factory()->create());
        $service->release($allocation);

        $this->expectException(InvalidAllocationException::class);
        $service->heartbeat($allocation->refresh());
    }

    public function test_heartbeat_on_null_lease_pool_is_a_graceful_noop()
    {
        Carbon::setTestNow(Carbon::create(2025, 6, 1, 12, 0, 0));

        try {
            $config = $this->createFloatingConfig(null, ['lease_duration_minutes' => null]);
            $service = app(FloatingLicenseService::class);
            $allocation = $service->allocate($config, User::factory()->create());

            Carbon::setTestNow(Carbon::create(2025, 6, 2, 12, 0, 0));

            $service->heartbeat($allocation);

            $allocation->refresh();
            $this->assertEquals(Carbon::now()->toDateTimeString(), $allocation->last_seen_at->toDateTimeString());
            $this->assertNull($allocation->expires_at, 'A null lease pool must never gain an expires_at');
            $this->assertTrue($allocation->isActive());
        } finally {
            Carbon::setTestNow();
        }
    }
}
