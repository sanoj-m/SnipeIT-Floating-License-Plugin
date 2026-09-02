<?php

namespace SnipeIt\FloatingLicenses\Tests\Feature;

use App\Models\License;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;
use SnipeIt\FloatingLicenses\Tests\TestCase;

class LicensesApiAvailabilityTest extends TestCase
{
    /**
     * Fetch the licenses index and return the row for the given license id.
     *
     * @return array<string, mixed>
     */
    private function licenseRowFromApi(License $license): array
    {
        $response = $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.licenses.index'))
            ->assertOk();

        foreach ($response->json('rows') as $row) {
            if ((int) $row['id'] === (int) $license->id) {
                return $row;
            }
        }

        $this->fail('License '.$license->id.' not present in the licenses API index rows');
    }

    public function test_index_avail_and_percent_reflect_floating_allocations()
    {
        $license = License::factory()->create(['seats' => 35]);
        $config = $this->createFloatingConfig($license, ['pool_size' => 35, 'allow_over_allocation' => true]);

        $service = app(FloatingLicenseService::class);
        foreach (range(1, 10) as $i) {
            $service->allocate($config, User::factory()->create());
        }

        $row = $this->licenseRowFromApi($license);

        $this->assertEquals(35, $row['seats']);
        $this->assertEquals(25, $row['free_seats_count'], 'Avail must be seats minus active floating allocations');
        $this->assertEquals(25, $row['remaining']);
        $this->assertEquals(71, $row['percent_remaining'], '25/35 rounds to 71 percent');
    }

    public function test_index_avail_goes_negative_when_over_allocated_and_percent_clamps_to_zero()
    {
        $license = License::factory()->create(['seats' => 2]);
        $config = $this->createFloatingConfig($license, ['pool_size' => 2, 'allow_over_allocation' => true]);

        $service = app(FloatingLicenseService::class);
        foreach (range(1, 3) as $i) {
            $service->allocate($config, User::factory()->create());
        }

        $row = $this->licenseRowFromApi($license);

        $this->assertEquals(-1, $row['free_seats_count'], 'Negative avail is correct when over-allocated');
        $this->assertEquals(-1, $row['remaining']);
        $this->assertEquals(0, $row['percent_remaining'], 'The percentage bar is clamped to 0-100 for display');
    }

    public function test_index_is_untouched_when_master_switch_off()
    {
        $license = License::factory()->create(['seats' => 35]);
        $config = $this->createFloatingConfig($license, ['pool_size' => 35, 'allow_over_allocation' => true]);
        app(FloatingLicenseService::class)->allocate($config, User::factory()->create());

        $this->disableFloatingLicenses();

        $row = $this->licenseRowFromApi($license);

        $this->assertEquals(35, $row['free_seats_count'], 'Core seat math must be untouched while the addon is off');
        $this->assertEquals(100, $row['percent_remaining']);
    }

    public function test_index_uses_flat_grouped_queries_for_floating_data()
    {
        $service = app(FloatingLicenseService::class);
        foreach (range(1, 3) as $i) {
            $license = License::factory()->create(['seats' => 5]);
            $config = $this->createFloatingConfig($license, ['pool_size' => 5, 'allow_over_allocation' => true]);
            $service->allocate($config, User::factory()->create());
        }

        DB::enableQueryLog();
        $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.licenses.index'))
            ->assertOk();

        $floatingQueries = collect(DB::getQueryLog())
            ->filter(fn ($query) => str_contains($query['query'], 'floating_license'))
            ->count();

        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            2,
            $floatingQueries,
            'Floating adjustments must be one configs query + one grouped allocations query, not per-row'
        );
    }

    public function test_seat_transformer_suppresses_checkout_action_for_floating_licenses()
    {
        $apiUser = User::factory()->superuser()->create();

        $floatingLicense = License::factory()->create(['seats' => 2]);
        $this->createFloatingConfig($floatingLicense, ['pool_size' => 2]);

        $fixedLicense = License::factory()->create(['seats' => 2]);

        $floatingRows = $this->actingAsForApi($apiUser)
            ->getJson(route('api.licenses.seats.index', [$floatingLicense->id, 'status' => 'available']))
            ->assertOk()
            ->json('rows');

        $this->assertCount(2, $floatingRows);
        foreach ($floatingRows as $row) {
            $this->assertFalse($row['available_actions']['checkout'], 'Floating license seats must not offer per-seat checkout');
            $this->assertFalse($row['user_can_checkout']);
        }

        $fixedRows = $this->actingAsForApi($apiUser)
            ->getJson(route('api.licenses.seats.index', [$fixedLicense->id, 'status' => 'available']))
            ->assertOk()
            ->json('rows');

        $this->assertCount(2, $fixedRows);
        foreach ($fixedRows as $row) {
            $this->assertTrue($row['available_actions']['checkout'], 'Fixed license seats keep the core checkout action');
            $this->assertTrue($row['user_can_checkout']);
        }
    }

    public function test_seat_transformer_is_untouched_when_master_switch_off()
    {
        $license = License::factory()->create(['seats' => 1]);
        $this->createFloatingConfig($license, ['pool_size' => 1]);

        $this->disableFloatingLicenses();

        $rows = $this->actingAsForApi(User::factory()->superuser()->create())
            ->getJson(route('api.licenses.seats.index', [$license->id, 'status' => 'available']))
            ->assertOk()
            ->json('rows');

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['available_actions']['checkout']);
        $this->assertTrue($rows[0]['user_can_checkout']);
    }
}
