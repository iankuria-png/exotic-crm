<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientMediaDisplayImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_media_accepts_legacy_single_file_plus_files_payload_without_false_multi_file_error(): void
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

        $mediaUrl = rtrim($platform->wp_api_url, '/') . "/clients/{$client->wp_post_id}/media";
        $mediaGetCount = 0;
        $mediaPostCount = 0;

        Http::fake(function ($request) use ($mediaUrl, &$mediaGetCount, &$mediaPostCount) {
            if ($request->method() === 'GET' && $request->url() === $mediaUrl) {
                $mediaGetCount++;

                return Http::response([
                    'data' => $mediaGetCount === 1 ? [] : [
                        [
                            'id' => 62130,
                            'url' => 'https://www.exoticghana.com/wp-content/uploads/uploaded-video.mp4',
                            'mime_type' => 'video/mp4',
                            'is_main' => false,
                        ],
                    ],
                ], 200);
            }

            if ($request->method() === 'POST' && $request->url() === $mediaUrl) {
                $mediaPostCount++;

                return Http::response([
                    'attachment' => [
                        'id' => 62130,
                        'url' => 'https://www.exoticghana.com/wp-content/uploads/uploaded-video.mp4',
                        'mime_type' => 'video/mp4',
                        'is_main' => false,
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4');

        $this->post("/api/crm/clients/{$client->id}/media", [
            'file' => $file,
            'files' => [$file],
            'reason' => 'Upload video from test',
        ])
            ->assertOk()
            ->assertJsonPath('uploaded_count', 1)
            ->assertJsonPath('attachment.mime_type', 'video/mp4');

        $this->assertSame(2, $mediaGetCount);
        $this->assertSame(1, $mediaPostCount);

        Http::assertNotSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === rtrim($platform->wp_api_url, '/') . "/clients/{$client->wp_post_id}");
    }

    public function test_upload_media_rejects_mixed_image_and_video_batches_with_validation_status(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://ghana.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 61783,
        ]);
        $user = User::query()->create([
            'name' => 'Sales User',
            'email' => 'sales-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        Http::fake();
        Sanctum::actingAs($user);

        $image = UploadedFile::fake()->image('photo.jpg');
        $video = UploadedFile::fake()->create('clip.mp4', 1024, 'video/mp4');

        $this->post("/api/crm/clients/{$client->id}/media", [
            'files' => [$image, $video],
            'reason' => 'Upload mixed media from test',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'You can upload multiple files at once only when all selected files are images.');

        Http::assertNothingSent();
    }

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
