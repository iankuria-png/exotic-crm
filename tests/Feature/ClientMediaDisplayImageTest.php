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

class ClientMediaDisplayImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_set_main_media_refreshes_cached_display_image_from_wordpress_media(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://ghana.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 61783,
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
            'https://ghana.example.test/wp-json/exotic-crm-sync/v1/clients/61783/media/62124/set-main' => Http::response([
                'ok' => true,
            ], 200),
            'https://ghana.example.test/wp-json/exotic-crm-sync/v1/clients/61783' => Http::response([
                'wp_post_id' => 61783,
                'wp_user_id' => $client->wp_user_id,
                'name' => $client->name,
                'post_status' => 'publish',
                'phone' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'main_image_url' => null,
                'last_online' => null,
            ], 200),
            'https://ghana.example.test/wp-json/exotic-crm-sync/v1/clients/61783/media' => Http::response([
                'data' => [
                    [
                        'id' => 62124,
                        'url' => 'https://www.exoticghana.com/wp-content/uploads/selected-main.webp',
                        'mime_type' => 'image/webp',
                        'is_main' => true,
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->patchJson("/api/crm/clients/{$client->id}/media/62124/set-main", [
            'reason' => 'Set main from test',
        ])->assertOk();

        $client->refresh();

        $this->assertSame('https://www.exoticghana.com/wp-content/uploads/selected-main.webp', $client->display_image_url);
        $this->assertSame('wp_media_main', $client->display_image_source);
        $this->assertNotNull($client->display_image_checked_at);
    }
}
