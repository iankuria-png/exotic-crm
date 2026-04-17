<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientSyncExclusion;
use App\Models\Platform;
use App\Services\ClientSyncService;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientSyncExclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_sync_skips_excluded_wordpress_posts(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Sync Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        ClientSyncExclusion::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9401,
            'reason' => 'Deleted from CRM',
            'deleted_by' => null,
            'created_at' => now(),
        ]);

        Http::fake([
            'https://example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [[
                    'wp_post_id' => 9401,
                    'wp_user_id' => 8401,
                    'name' => 'Excluded Client',
                    'phone' => '0711000001',
                    'email' => 'excluded@example.test',
                    'city' => 'Nairobi',
                    'post_status' => 'publish',
                ]],
                'pages' => 1,
            ], 200),
        ]);

        $result = (new ClientSyncService($platform))->fullSync();

        $this->assertSame([
            'created' => 0,
            'updated' => 0,
            'skipped' => 1,
            'total' => 1,
        ], $result);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_delta_sync_uses_wordpress_modified_watermark_with_overlap(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Delta Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 1201,
            'wp_user_id' => 2201,
            'client_type' => 'escort',
            'name' => 'Existing Client',
            'phone_normalized' => '254711000001',
            'email' => 'existing@example.test',
            'profile_status' => 'publish',
            'last_synced_at' => now(),
            'wp_modified_at' => '2026-04-03 10:00:00',
        ]);

        Http::fake([
            'https://example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [],
                'pages' => 1,
            ], 200),
        ]);

        $result = (new ClientSyncService($platform))->deltaSync(50);

        $this->assertSame([
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'total' => 0,
        ], $result);

        Http::assertSent(function (ClientRequest $request) {
            return $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://example.test/wp-json/exotic-crm-sync/v1/clients')
                && $request['per_page'] === 50
                && $request['modified_after'] === '2026-04-03T09:55:00+00:00';
        });
    }

    public function test_full_sync_persists_wordpress_modified_timestamp_for_future_delta_runs(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Modified Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        Http::fake([
            'https://example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [[
                    'wp_post_id' => 9301,
                    'wp_user_id' => 8301,
                    'name' => 'Synced Client',
                    'phone' => '0711000002',
                    'email' => 'synced@example.test',
                    'city' => 'Nairobi',
                    'post_status' => 'publish',
                    'modified_at' => '2026-04-03 11:22:33',
                ]],
                'pages' => 1,
            ], 200),
        ]);

        $result = (new ClientSyncService($platform))->fullSync();

        $this->assertSame([
            'created' => 1,
            'updated' => 0,
            'skipped' => 0,
            'total' => 1,
        ], $result);

        $this->assertDatabaseHas('clients', [
            'platform_id' => $platform->id,
            'wp_post_id' => 9301,
            'wp_modified_at' => '2026-04-03 11:22:33',
        ]);
    }

    public function test_full_sync_persists_wordpress_subscription_activation_flags(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Flag Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        Http::fake([
            'https://example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [[
                    'wp_post_id' => 9402,
                    'wp_user_id' => 8402,
                    'name' => 'Flagged Client',
                    'phone' => '0711000003',
                    'email' => 'flagged@example.test',
                    'city' => 'Nairobi',
                    'post_status' => 'publish',
                    'needs_payment' => false,
                    'notactive' => true,
                    'modified_at' => '2026-04-17 08:30:00',
                ]],
                'pages' => 1,
            ], 200),
        ]);

        $result = (new ClientSyncService($platform))->fullSync();

        $this->assertSame([
            'created' => 1,
            'updated' => 0,
            'skipped' => 0,
            'total' => 1,
        ], $result);

        $this->assertDatabaseHas('clients', [
            'platform_id' => $platform->id,
            'wp_post_id' => 9402,
            'needs_payment' => 0,
            'notactive' => 1,
            'wp_modified_at' => '2026-04-17 08:30:00',
        ]);
    }
}
