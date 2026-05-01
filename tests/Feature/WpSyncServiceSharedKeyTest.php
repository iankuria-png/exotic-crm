<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Services\WpSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WpSyncServiceSharedKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_platform_keeps_existing_basic_auth_only_headers(): void
    {
        config([
            'services.exotic_crm_sync.shared_key' => 'temporary-token',
            'services.exotic_crm_sync.shared_key_platform_ids' => '99999',
        ]);

        $platform = $this->makePlatform();

        Http::fake([
            rtrim($platform->wp_api_url, '/') . '/stats' => Http::response(['total' => 0], 200),
        ]);

        (new WpSyncService($platform))->getStats();

        Http::assertSent(function (Request $request) use ($platform): bool {
            return $request->url() === rtrim($platform->wp_api_url, '/') . '/stats'
                && $request->hasHeader('Authorization', 'Basic ' . base64_encode($platform->wp_api_user . ':' . $platform->wp_api_password))
                && !$request->hasHeader('X-Exotic-CRM-Sync-Key');
        });
    }

    public function test_configured_platform_sends_shared_key_on_all_wordpress_request_paths(): void
    {
        $platform = $this->makePlatform();
        config([
            'services.exotic_crm_sync.shared_key' => 'temporary-token',
            'services.exotic_crm_sync.shared_key_platform_ids' => (string) $platform->id,
        ]);

        $baseUrl = rtrim($platform->wp_api_url, '/');
        Http::fake([
            $baseUrl . '/stats' => Http::response(['total' => 0], 200),
            $baseUrl . '/clients/321/update' => Http::response(['success' => true], 200),
            $baseUrl . '/clients/321/media/654/set-main' => Http::response(['success' => true], 200),
            $baseUrl . '/clients/321/media/654' => Http::response(['success' => true], 200),
            $baseUrl . '/clients/321/media' => Http::response([
                'attachment' => [
                    'id' => 654,
                    'url' => 'https://example.test/media/image.jpg',
                ],
            ], 200),
        ]);

        $sync = new WpSyncService($platform);
        $sync->getStats();
        $sync->updateClientProfile(321, ['name' => 'Updated Name']);
        $sync->setClientMainImage(321, 654);
        $sync->deleteClientMedia(321, 654);
        $sync->uploadClientMedia(321, UploadedFile::fake()->image('image.jpg'), true);

        foreach ([
            ['GET', $baseUrl . '/stats'],
            ['POST', $baseUrl . '/clients/321/update'],
            ['PATCH', $baseUrl . '/clients/321/media/654/set-main'],
            ['DELETE', $baseUrl . '/clients/321/media/654'],
            ['POST', $baseUrl . '/clients/321/media'],
        ] as [$method, $url]) {
            Http::assertSent(function (Request $request) use ($method, $url, $platform): bool {
                return $request->method() === $method
                    && $request->url() === $url
                    && $request->hasHeader('Authorization', 'Basic ' . base64_encode($platform->wp_api_user . ':' . $platform->wp_api_password))
                    && $request->hasHeader('X-Exotic-CRM-Sync-Key', 'temporary-token');
            });
        }
    }

    private function makePlatform(): Platform
    {
        return Platform::factory()->create([
            'wp_api_url' => 'https://ug-sync.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
