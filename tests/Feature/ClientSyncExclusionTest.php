<?php

namespace Tests\Feature;

use App\Models\ClientSyncExclusion;
use App\Models\Platform;
use App\Services\ClientSyncService;
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
}
