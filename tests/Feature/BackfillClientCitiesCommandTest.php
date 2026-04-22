<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillClientCitiesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_dry_run_reports_resolvable_city_without_updating_client(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'DRC',
            'wp_api_url' => 'https://drc.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 2055,
            'client_type' => 'escort',
            'name' => 'Lala',
            'city' => '84',
        ]);

        Http::fake([
            'https://drc.example.test/wp-json/exotic-crm-sync/v1/clients/2055' => Http::response([
                'data' => [
                    'city' => '84',
                    'taxonomies' => [
                        'city' => [
                            'name' => 'Lubumbashi',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('crm:backfill-client-cities', ['platform' => (string) $platform->id])
            ->assertExitCode(0);

        $this->assertSame('84', (string) $client->fresh()->city);
    }

    public function test_command_apply_updates_numeric_city_from_wordpress_profile_payload(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'DRC',
            'wp_api_url' => 'https://drc.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 2055,
            'client_type' => 'escort',
            'name' => 'Lala',
            'city' => '84',
        ]);

        Http::fake([
            'https://drc.example.test/wp-json/exotic-crm-sync/v1/clients/2055' => Http::response([
                'data' => [
                    'city' => '84',
                    'taxonomies' => [
                        'city' => [
                            'name' => 'Lubumbashi',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $existingBackups = glob(storage_path('app/backfills/client-city-backfill-platform-' . $platform->id . '-*.json')) ?: [];

        $this->artisan('crm:backfill-client-cities', [
            'platform' => (string) $platform->id,
            '--apply' => true,
        ])
            ->assertExitCode(0);

        $this->assertSame('Lubumbashi', (string) $client->fresh()->city);

        $newBackups = glob(storage_path('app/backfills/client-city-backfill-platform-' . $platform->id . '-*.json')) ?: [];
        $createdBackups = array_values(array_diff($newBackups, $existingBackups));

        $this->assertNotEmpty($createdBackups);
    }
}
