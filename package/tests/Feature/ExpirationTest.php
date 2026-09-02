<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\License;
use App\Models\User;
use Carbon\Carbon;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class ExpirationTest extends TestCase
{
    public function test_expire_command_expires_allocations_past_lease()
    {
        Carbon::setTestNow(Carbon::create(2025, 6, 1, 12, 0, 0));

        try {
            $config = $this->createFloatingConfig(null, ['lease_duration_minutes' => 60]);
            $allocation = app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

            // Move past the lease expiry (expires_at = 13:00)
            Carbon::setTestNow(Carbon::create(2025, 6, 1, 13, 1, 0));

            $this->artisan('floating-licenses:expire')->assertSuccessful();

            $allocation->refresh();
            $this->assertEquals(FloatingLicenseAllocation::STATUS_EXPIRED, $allocation->status);
            $this->assertNotNull($allocation->released_at);

            $this->assertDatabaseHas('action_logs', [
                'item_type' => License::class,
                'item_id' => $config->license_id,
                'action_type' => 'floating.expire',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expire_command_expires_idle_allocations_before_lease_ends()
    {
        Carbon::setTestNow(Carbon::create(2025, 6, 1, 12, 0, 0));

        try {
            $config = $this->createFloatingConfig(null, [
                'lease_duration_minutes' => 120,
                'idle_timeout_minutes' => 30,
            ]);
            $allocation = app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

            // 31 minutes with no heartbeat: lease is still valid (expires at
            // 14:00) but the allocation has gone idle past the 30 min timeout.
            Carbon::setTestNow(Carbon::create(2025, 6, 1, 12, 31, 0));

            $this->artisan('floating-licenses:expire')->assertSuccessful();

            $this->assertEquals(
                FloatingLicenseAllocation::STATUS_EXPIRED,
                $allocation->refresh()->status
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expire_command_leaves_fresh_allocations_active()
    {
        Carbon::setTestNow(Carbon::create(2025, 6, 1, 12, 0, 0));

        try {
            $config = $this->createFloatingConfig(null, [
                'lease_duration_minutes' => 120,
                'idle_timeout_minutes' => 60,
            ]);
            $allocation = app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

            // Well within both lease and idle windows.
            Carbon::setTestNow(Carbon::create(2025, 6, 1, 12, 10, 0));

            $this->artisan('floating-licenses:expire')->assertSuccessful();

            $this->assertTrue($allocation->refresh()->isActive());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_expire_command_leaves_null_duration_allocations_active()
    {
        Carbon::setTestNow(Carbon::create(2025, 6, 1, 12, 0, 0));

        try {
            // Null durations = no lease expiry and no idle reclamation.
            $config = $this->createFloatingConfig(null, [
                'lease_duration_minutes' => null,
                'idle_timeout_minutes' => null,
            ]);
            $allocation = app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

            $this->assertNull($allocation->expires_at, 'A null lease duration must produce a null expires_at');

            // Far in the future, with no heartbeat at all.
            Carbon::setTestNow(Carbon::create(2026, 6, 1, 12, 0, 0));

            $this->artisan('floating-licenses:expire')->assertSuccessful();

            $this->assertTrue(
                $allocation->refresh()->isActive(),
                'Allocations whose pool has no durations must never be expired'
            );
        } finally {
            Carbon::setTestNow();
        }
    }
}
