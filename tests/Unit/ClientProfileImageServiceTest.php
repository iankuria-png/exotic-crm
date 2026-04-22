<?php

namespace Tests\Unit;

use App\Services\ClientProfileImageService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientProfileImageServiceTest extends TestCase
{
    public function test_select_display_image_prefers_reachable_main_image(): void
    {
        Http::fake([
            'https://cdn.example.test/main.webp' => Http::response('', 200, ['Content-Type' => 'image/webp']),
        ]);

        $selection = app(ClientProfileImageService::class)->selectDisplayImage([
            'data' => [
                ['id' => 10, 'url' => 'https://cdn.example.test/fallback.webp', 'mime_type' => 'image/webp', 'is_main' => false],
                ['id' => 11, 'url' => 'https://cdn.example.test/main.webp', 'mime_type' => 'image/webp', 'is_main' => true],
            ],
        ], verifyReachable: true);

        $this->assertSame('https://cdn.example.test/main.webp', $selection['url'] ?? null);
        $this->assertSame('wp_media_main', $selection['source'] ?? null);
    }

    public function test_select_display_image_ignores_main_video_and_uses_first_image(): void
    {
        $selection = app(ClientProfileImageService::class)->selectDisplayImage([
            'data' => [
                ['id' => 10, 'url' => 'https://cdn.example.test/main.mp4', 'mime_type' => 'video/mp4', 'is_main' => true],
                ['id' => 11, 'url' => 'https://cdn.example.test/fallback.webp', 'mime_type' => 'image/webp', 'is_main' => false],
            ],
        ]);

        $this->assertSame('https://cdn.example.test/fallback.webp', $selection['url'] ?? null);
        $this->assertSame('wp_media_first', $selection['source'] ?? null);
    }

    public function test_select_display_image_falls_back_when_main_image_is_broken(): void
    {
        Http::fake([
            'https://cdn.example.test/broken.webp' => Http::response('', 404),
            'https://cdn.example.test/fallback.webp' => Http::response('', 200, ['Content-Type' => 'image/webp']),
        ]);

        $selection = app(ClientProfileImageService::class)->selectDisplayImage([
            'data' => [
                ['id' => 10, 'url' => 'https://cdn.example.test/broken.webp', 'mime_type' => 'image/webp', 'is_main' => true],
                ['id' => 11, 'url' => 'https://cdn.example.test/fallback.webp', 'mime_type' => 'image/webp', 'is_main' => false],
            ],
        ], verifyReachable: true);

        $this->assertSame('https://cdn.example.test/fallback.webp', $selection['url'] ?? null);
        $this->assertSame('wp_media_first', $selection['source'] ?? null);
    }

    public function test_select_display_image_returns_null_when_no_valid_image_exists(): void
    {
        $selection = app(ClientProfileImageService::class)->selectDisplayImage([
            'data' => [
                ['id' => 10, 'url' => 'https://cdn.example.test/main.mp4', 'mime_type' => 'video/mp4', 'is_main' => true],
            ],
        ]);

        $this->assertNull($selection);
    }
}
