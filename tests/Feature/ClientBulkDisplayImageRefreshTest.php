<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientBulkDisplayImageRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_refresh_updates_selected_client_thumbnails_without_full_profile_sync(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://ghana.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $firstClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 61783,
            'main_image_url' => null,
            'display_image_url' => null,
        ]);
        $secondClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 61784,
            'main_image_url' => null,
            'display_image_url' => 'https://old.example.test/old.webp',
        ]);
        $crmOnlyClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 0,
            'main_image_url' => null,
            'display_image_url' => null,
        ]);

        $user = User::query()->create([
            'name' => 'Sales User',
            'email' => 'sales-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        Http::fake([
            'https://ghana.example.test/wp-json/exotic-crm-sync/v1/clients/61783/media' => Http::response([
                'data' => [
                    [
                        'id' => 62123,
                        'url' => 'https://www.exoticghana.com/wp-content/uploads/first.webp',
                        'mime_type' => 'image/webp',
                        'is_main' => true,
                    ],
                ],
            ], 200),
            'https://ghana.example.test/wp-json/exotic-crm-sync/v1/clients/61784/media' => Http::response([
                'data' => [
                    [
                        'id' => 62124,
                        'url' => 'https://www.exoticghana.com/wp-content/uploads/second.webp',
                        'mime_type' => 'image/webp',
                        'is_main' => false,
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/clients/bulk-refresh-display-images', [
            'client_ids' => [
                $firstClient->id,
                $secondClient->id,
                $crmOnlyClient->id,
            ],
        ])->assertOk();

        $response->assertJson([
            'processed_count' => 3,
            'refreshed_count' => 2,
            'cleared_count' => 0,
            'skipped_count' => 1,
            'failed_count' => 0,
        ]);

        $firstClient->refresh();
        $secondClient->refresh();
        $crmOnlyClient->refresh();

        $this->assertSame('https://www.exoticghana.com/wp-content/uploads/first.webp', $firstClient->display_image_url);
        $this->assertSame('wp_media_main', $firstClient->display_image_source);
        $this->assertNotNull($firstClient->display_image_checked_at);

        $this->assertSame('https://www.exoticghana.com/wp-content/uploads/second.webp', $secondClient->display_image_url);
        $this->assertSame('wp_media_first', $secondClient->display_image_source);
        $this->assertNotNull($secondClient->display_image_checked_at);

        $this->assertNull($crmOnlyClient->display_image_url);
        $this->assertNull($crmOnlyClient->display_image_checked_at);
    }
}
