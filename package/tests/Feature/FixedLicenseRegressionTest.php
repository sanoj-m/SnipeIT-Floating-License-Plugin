<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\License;
use App\Models\LicenseSeat;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseAllocation;
use SnipeIt\FloatingLicenses\Models\FloatingLicenseConfig;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class FixedLicenseRegressionTest extends TestCase
{
    /**
     * Installing the package must not change how plain fixed-seat licenses
     * behave: creating a License via its factory still seeds license_seats
     * rows (License::boot()'s created hook) and leaves the floating tables
     * untouched.
     */
    public function test_creating_a_plain_license_still_creates_license_seats()
    {
        $license = License::factory()->create(['seats' => 7]);

        $this->assertEquals(
            7,
            LicenseSeat::where('license_id', $license->id)->count(),
            'License::factory() should still create one license_seats row per seat'
        );

        $this->assertEquals(
            0,
            FloatingLicenseConfig::where('license_id', $license->id)->count(),
            'A plain license must not gain a floating config implicitly'
        );
        $this->assertEquals(0, FloatingLicenseAllocation::count());
    }
}
