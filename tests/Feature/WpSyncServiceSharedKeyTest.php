<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Services\WpSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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
            rtrim($platform->wp_api_url, '/').'/stats' => Http::response(['total' => 0], 200),
        ]);

        (new WpSyncService($platform))->getStats();

        Http::assertSent(function (Request $request) use ($platform): bool {
            return $request->url() === rtrim($platform->wp_api_url, '/').'/stats'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode($platform->wp_api_user.':'.$platform->wp_api_password))
                && ! $request->hasHeader('X-Exotic-CRM-Sync-Key');
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
            $baseUrl.'/stats' => Http::response(['total' => 0], 200),
            $baseUrl.'/clients/321/update' => Http::response(['success' => true], 200),
            $baseUrl.'/clients/321/media/654/set-main' => Http::response(['success' => true], 200),
            $baseUrl.'/clients/321/media/654' => Http::response(['success' => true], 200),
            $baseUrl.'/clients/321/media' => Http::response([
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
            ['GET', $baseUrl.'/stats'],
            ['POST', $baseUrl.'/clients/321/update'],
            ['PATCH', $baseUrl.'/clients/321/media/654/set-main'],
            ['DELETE', $baseUrl.'/clients/321/media/654'],
            ['POST', $baseUrl.'/clients/321/media'],
        ] as [$method, $url]) {
            Http::assertSent(function (Request $request) use ($method, $url, $platform): bool {
                return $request->method() === $method
                    && $request->url() === $url
                    && $request->hasHeader('Authorization', 'Basic '.base64_encode($platform->wp_api_user.':'.$platform->wp_api_password))
                    && $request->hasHeader('X-Exotic-CRM-Sync-Key', 'temporary-token');
            });
        }
    }

    public function test_repeated_known_market_failure_is_gated_before_wordpress_is_called(): void
    {
        $platform = $this->makePlatform();
        $platform->forceFill([
            'health_status' => 'server_error',
            'health_consecutive_failures' => 2,
        ])->save();

        Http::fake(function (): void {
            $this->fail('A repeatedly failed market must be rejected before an HTTP request is made.');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('temporarily gated');

        (new WpSyncService($platform))->getStats();
    }

    public function test_http_500_is_not_retried_for_wordpress_reads(): void
    {
        $platform = $this->makePlatform();
        $baseUrl = rtrim($platform->wp_api_url, '/');

        Http::fake([
            $baseUrl.'/stats' => Http::response(['message' => 'upstream failure'], 500),
        ]);

        try {
            (new WpSyncService($platform))->getStats();
            $this->fail('Expected the failed WordPress response to throw.');
        } catch (RequestException) {
            // Expected: a server error is surfaced after one attempt.
        }

        Http::assertSentCount(1);
    }

    public function test_profile_analytics_is_cached_by_profile_and_date_range(): void
    {
        $platform = $this->makePlatform();
        $baseUrl = rtrim($platform->wp_api_url, '/');

        Http::fake([
            $baseUrl.'/analytics/321*' => Http::response(['views' => 12], 200),
        ]);

        $sync = new WpSyncService($platform);
        $first = $sync->getAnalytics(321, '2026-09-01', '2026-09-10');
        $second = $sync->getAnalytics(321, '2026-09-01', '2026-09-10');

        $this->assertSame(['views' => 12], $first);
        $this->assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_locations_failure_is_negative_cached_to_avoid_repeated_wordpress_calls(): void
    {
        $platform = $this->makePlatform();
        $baseUrl = rtrim($platform->wp_api_url, '/');

        Http::fake([
            $baseUrl.'/locations' => Http::response(['message' => 'upstream failure'], 500),
        ]);

        $sync = new WpSyncService($platform);

        foreach ([1, 2] as $_) {
            try {
                $sync->getLocations();
                $this->fail('Expected the failed WordPress response to throw.');
            } catch (RequestException|\RuntimeException) {
                // The second failure should be served by the short negative cache.
            }
        }

        Http::assertSentCount(1);
    }

    public function test_kyc_failure_log_uses_the_actual_request_url_and_truncates_html_body(): void
    {
        $platform = $this->makePlatform();
        $requestUrl = 'https://ug-sync.example.test/wp-json/exotic-kyc/v1/subjects/321/status';
        $html = '<html>'.str_repeat('failure ', 300).'</html>';

        Http::fake([
            $requestUrl => Http::response($html, 503),
        ]);

        Log::shouldReceive('error')->once()->withArgs(function (string $message, array $context) use ($requestUrl, $html): bool {
            return $message === 'WpSyncService POST failed'
                && $context['url'] === $requestUrl
                && mb_strlen($context['body']) <= 1024
                && $context['body'] !== $html;
        });

        $this->expectException(RequestException::class);

        (new WpSyncService($platform))->pushKycSubjectStatus(321, ['status' => 'approved']);
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
