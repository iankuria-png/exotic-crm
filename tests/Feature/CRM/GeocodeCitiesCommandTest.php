<?php

namespace Tests\Feature\CRM;

use App\Models\CityGeocode;
use App\Models\Client;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodeCitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.nominatim.user_agent', 'ExoticCRM-Test/1.0');
        config()->set('services.nominatim.batch_limit', 10);
        config()->set('services.nominatim.rate_per_minute', 600);
    }

    public function test_resolved_city_stores_coordinates_and_importance(): void
    {
        $platform = Platform::factory()->create([
            'country' => 'Kenya',
        ]);

        Client::factory()->create([
            'platform_id' => $platform->id,
            'city' => 'Nairobi',
        ]);

        Http::fake(function (ClientRequest $request) {
            $this->assertSame('ke', $request['countrycodes']);
            $this->assertSame('ExoticCRM-Test/1.0', $request->header('User-Agent')[0] ?? null);

            return Http::response([
                [
                    'lat' => '-1.286389',
                    'lon' => '36.817223',
                    'importance' => 0.77,
                    'type' => 'city',
                ],
            ], 200);
        });

        $this->artisan('crm:geocode-cities', ['--platform' => (string) $platform->id, '--rate' => '600'])
            ->assertExitCode(0);

        $row = CityGeocode::query()->firstOrFail();

        $this->assertSame('resolved', $row->status);
        $this->assertSame('-1.2863890', $row->latitude);
        $this->assertSame('36.8172230', $row->longitude);
        $this->assertSame('0.77000', $row->importance);
        $this->assertSame('city', $row->match_type);
        $this->assertSame(1, $row->attempts);
    }

    public function test_empty_result_marks_city_unresolved(): void
    {
        $platform = Platform::factory()->create([
            'country' => 'Kenya',
        ]);

        Client::factory()->create([
            'platform_id' => $platform->id,
            'city' => 'Madeupville',
        ]);

        Http::fake([
            '*' => Http::response([], 200),
        ]);

        $this->artisan('crm:geocode-cities', ['--platform' => (string) $platform->id, '--rate' => '600'])
            ->assertExitCode(0);

        $row = CityGeocode::query()->firstOrFail();

        $this->assertSame('unresolved', $row->status);
        $this->assertSame(1, $row->attempts);
        $this->assertNull($row->latitude);
        $this->assertNull($row->longitude);
    }

    public function test_failed_requests_increment_attempts_and_are_retried(): void
    {
        $platform = Platform::factory()->create([
            'country' => 'Kenya',
        ]);

        Client::factory()->create([
            'platform_id' => $platform->id,
            'city' => 'Nakuru',
        ]);

        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return Http::response(['message' => 'upstream error'], 500);
        });

        $this->artisan('crm:geocode-cities', ['--platform' => (string) $platform->id, '--rate' => '600'])
            ->assertExitCode(0);
        $this->artisan('crm:geocode-cities', ['--platform' => (string) $platform->id, '--rate' => '600'])
            ->assertExitCode(0);

        $row = CityGeocode::query()->firstOrFail();

        $this->assertSame('failed', $row->status);
        $this->assertSame(2, $row->attempts);
        $this->assertSame(2, $calls);
        $this->assertStringContainsString('HTTP 500', (string) $row->failure_reason);
    }

    public function test_rerunning_enqueue_does_not_reset_resolved_rows_to_pending(): void
    {
        $platform = Platform::factory()->create([
            'country' => 'Kenya',
        ]);

        Client::factory()->create([
            'platform_id' => $platform->id,
            'city' => 'Nairobi',
        ]);

        CityGeocode::query()->create([
            'platform_id' => $platform->id,
            'canonical_key' => 'nairobi',
            'display_city' => 'Nairobi',
            'latitude' => -1.2863890,
            'longitude' => 36.8172230,
            'status' => 'resolved',
            'attempts' => 3,
        ]);

        Http::fake();

        $this->artisan('crm:geocode-cities', ['--platform' => (string) $platform->id, '--rate' => '600'])
            ->assertExitCode(0);

        $row = CityGeocode::query()->firstOrFail();

        $this->assertSame('resolved', $row->status);
        $this->assertSame(3, $row->attempts);
        $this->assertSame('-1.2863890', $row->latitude);
        $this->assertSame('36.8172230', $row->longitude);
        Http::assertNothingSent();
    }
}
