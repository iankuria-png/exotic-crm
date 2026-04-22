<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientDisplayImageRefreshCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_backfills_reachable_fallback_image_without_touching_client_updated_at(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 4, 22, 12, 0, 0));

        try {
            $platform = Platform::factory()->create([
                'wp_api_url' => 'https://ghana.example.test/wp-json/exotic-crm-sync/v1',
                'wp_api_user' => 'crm-user',
                'wp_api_password' => 'secret',
            ]);
            $originalUpdatedAt = Carbon::create(2026, 4, 1, 9, 0, 0);
            $client = Client::factory()->create([
                'platform_id' => $platform->id,
                'wp_post_id' => 61783,
                'main_image_url' => null,
                'display_image_url' => null,
                'updated_at' => $originalUpdatedAt,
            ]);

            Http::fake([
                'https://ghana.example.test/wp-json/exotic-crm-sync/v1/clients/61783/media' => Http::response([
                    'data' => [
                        [
                            'id' => 62125,
                            'url' => 'https://www.exoticghana.com/wp-content/uploads/main-broken.webp',
                            'mime_type' => 'image/webp',
                            'is_main' => true,
                        ],
                        [
                            'id' => 62123,
                            'url' => 'https://www.exoticghana.com/wp-content/uploads/fallback.webp',
                            'mime_type' => 'image/webp',
                            'is_main' => false,
                        ],
                    ],
                ], 200),
                'https://www.exoticghana.com/wp-content/uploads/main-broken.webp' => Http::response('', 404),
                'https://www.exoticghana.com/wp-content/uploads/fallback.webp' => Http::response('', 200, [
                    'Content-Type' => 'image/webp',
                ]),
            ]);

            $this->artisan('crm:refresh-client-display-images', [
                '--platform' => $platform->id,
                '--only-missing' => true,
                '--limit' => 1,
            ])->assertExitCode(0);

            $client->refresh();

            $this->assertSame('https://www.exoticghana.com/wp-content/uploads/fallback.webp', $client->display_image_url);
            $this->assertSame('wp_media_first', $client->display_image_source);
            $this->assertTrue($client->display_image_checked_at->equalTo(now()));
            $this->assertTrue($client->updated_at->equalTo($originalUpdatedAt));
        } finally {
            Carbon::setTestNow();
        }
    }
}
