<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncClientsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_continues_syncing_other_markets_after_one_market_fails(): void
    {
        $platformA = Platform::factory()->create([
            'name' => 'Kenya',
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $platformB = Platform::factory()->create([
            'name' => 'Tanzania',
            'wp_api_url' => 'https://tanzania.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $platformC = Platform::factory()->create([
            'name' => 'Ghana',
            'wp_api_url' => 'https://ghana.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        Http::fake([
            'https://kenya.example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [[
                    'wp_post_id' => 9101,
                    'wp_user_id' => 8101,
                    'name' => 'Kenya Client',
                    'phone' => '0711000101',
                    'email' => 'kenya@example.test',
                    'city' => 'Nairobi',
                    'post_status' => 'publish',
                    'modified_at' => '2026-04-03 12:00:00',
                ]],
                'pages' => 1,
            ], 200),
            'https://tanzania.example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'message' => 'Upstream outage',
            ], 500),
            'https://ghana.example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [[
                    'wp_post_id' => 9301,
                    'wp_user_id' => 8301,
                    'name' => 'Ghana Client',
                    'phone' => '0711000301',
                    'email' => 'ghana@example.test',
                    'city' => 'Accra',
                    'post_status' => 'publish',
                    'modified_at' => '2026-04-03 12:05:00',
                ]],
                'pages' => 1,
            ], 200),
        ]);

        $this->artisan('crm:sync-clients')
            ->assertExitCode(1);

        $this->assertDatabaseHas('clients', [
            'platform_id' => $platformA->id,
            'wp_post_id' => 9101,
        ]);
        $this->assertDatabaseHas('clients', [
            'platform_id' => $platformC->id,
            'wp_post_id' => 9301,
        ]);
        $this->assertDatabaseCount('clients', 2);

        $platformA->refresh();
        $platformB->refresh();
        $platformC->refresh();

        $this->assertSame('success', $platformA->sync_last_status);
        $this->assertSame('scheduler', data_get($platformA->sync_last_result, 'trigger'));
        $this->assertSame('delta', data_get($platformA->sync_last_result, 'mode'));

        $this->assertSame('error', $platformB->sync_last_status);
        $this->assertSame('scheduler', data_get($platformB->sync_last_result, 'trigger'));
        $this->assertSame('delta', data_get($platformB->sync_last_result, 'mode'));
        $this->assertNotEmpty($platformB->sync_last_error);

        $this->assertSame('success', $platformC->sync_last_status);
        $this->assertSame('scheduler', data_get($platformC->sync_last_result, 'trigger'));

        Http::assertSentCount(3);
    }

    public function test_command_skips_markets_without_complete_wordpress_credentials(): void
    {
        Platform::factory()->create([
            'name' => 'Incomplete Market',
            'wp_api_url' => 'https://incomplete.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => null,
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Complete Market',
            'wp_api_url' => 'https://complete.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        Http::fake([
            'https://complete.example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [[
                    'wp_post_id' => 9401,
                    'wp_user_id' => 8401,
                    'name' => 'Complete Client',
                    'phone' => '0711000401',
                    'email' => 'complete@example.test',
                    'city' => 'Kampala',
                    'post_status' => 'publish',
                    'modified_at' => '2026-04-03 12:10:00',
                ]],
                'pages' => 1,
            ], 200),
        ]);

        $this->artisan('crm:sync-clients')
            ->assertExitCode(0);

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('clients', [
            'platform_id' => $platform->id,
            'wp_post_id' => 9401,
        ]);

        Http::assertSentCount(1);
    }
}
