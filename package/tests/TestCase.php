<?php

namespace SnipeIt\FloatingLicenses\Tests;

use App\Models\License;
use App\Models\User;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use Tests\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The addon's master switch (settings.floating_licenses_enabled) is
        // on by default in tests; individual tests can call
        // disableFloatingLicenses() to exercise the off behavior.
        $this->enableFloatingLicenses();
    }

    protected function enableFloatingLicenses(): void
    {
        $this->settings->set(['floating_licenses_enabled' => 1]);
    }

    protected function disableFloatingLicenses(): void
    {
        $this->settings->set(['floating_licenses_enabled' => 0]);
    }

    /**
     * Create a user holding exactly the given floating-licenses permissions.
     *
     * Mirrors how UserFactory::appendPermission() encodes grants: a JSON map
     * of permission name to '1' stored in the users.permissions column.
     *
     * @param  string[]  $permissions
     */
    protected function createUserWithFloatingPermissions(array $permissions): User
    {
        $grants = [];
        foreach ($permissions as $permission) {
            $grants[$permission] = '1';
        }

        return User::factory()->create(['permissions' => json_encode($grants)]);
    }

    /**
     * Create a floating pool config for a license (a fresh one if not given),
     * with sensible defaults that individual tests can override. Durations
     * default to null (allocations never expire / no idle reclamation).
     */
    protected function createFloatingConfig(?License $license = null, array $attributes = []): FloatingLicenseConfig
    {
        $license ??= License::factory()->create();

        return FloatingLicenseConfig::create(array_merge([
            'license_id' => $license->id,
            'pool_size' => 5,
            'total_cost' => 1000,
            'cost_mode' => FloatingLicenseConfig::COST_MODE_POOL_SLOT,
            'allow_over_allocation' => false,
            'lease_duration_minutes' => null,
            'idle_timeout_minutes' => null,
        ], $attributes));
    }
}
