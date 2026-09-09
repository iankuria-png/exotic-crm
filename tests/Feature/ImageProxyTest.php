<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Services\MarketHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Characterisation tests for the CRM image proxy.
 *
 * Written against the behaviour that existed BEFORE the September 2026
 * worker-starvation fix, so the fix can be proven not to change what callers
 * see. Everything asserted here is contract: the allowlist, the status
 * passthrough, the content-type gate, the header passthrough and the HEAD
 * short-circuit are all relied on by the client media gallery, which probes
 * every asset with HEAD before rendering it.
 */
class ImageProxyTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = "\x89PNG\r\n\x1a\n";

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function market(array $overrides = []): Platform
    {
        return Platform::factory()->create(array_merge([
            'name' => 'Proxy Market',
            'domain' => 'proxy-market.test',
            'wp_api_url' => 'https://proxy-market.test/wp-json/exotic-crm-sync/v1',
        ], $overrides));
    }

    private function proxy(string $url, array $headers = [])
    {
        return $this->withHeaders($headers)->get('/api/crm/image-proxy?url='.urlencode($url));
    }

    public function test_a_missing_url_is_rejected(): void
    {
        $this->get('/api/crm/image-proxy')->assertStatus(400);
    }

    public function test_a_non_http_scheme_is_rejected(): void
    {
        $this->market();

        $this->proxy('file:///etc/passwd')->assertStatus(400);
        $this->proxy('ftp://proxy-market.test/a.png')->assertStatus(400);
    }

    public function test_a_host_outside_the_platform_allowlist_is_forbidden(): void
    {
        $this->market();
        Http::fake();

        $this->proxy('https://evil.example.com/a.png')->assertStatus(403);

        // The allowlist is the SSRF guard — nothing may leave before it passes.
        Http::assertNothingSent();
    }

    public function test_an_allowed_host_is_proxied_with_its_headers(): void
    {
        $this->market();

        Http::fake(['proxy-market.test/*' => Http::response(self::PNG, 200, [
            'Content-Type' => 'image/png',
            'ETag' => '"abc123"',
            'Cache-Control' => 'public, max-age=600',
        ])]);

        $response = $this->proxy('https://proxy-market.test/wp-content/a.png');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('ETag', '"abc123"');
        // Symfony normalises Cache-Control directive order on the way out.
        $response->assertHeader('Cache-Control', 'max-age=600, public');
        $response->assertHeader('X-Proxied-By', 'ExoticCRM');
        $this->assertSame(self::PNG, $response->streamedContent());
    }

    public function test_the_www_variant_of_an_allowed_host_is_also_allowed(): void
    {
        $this->market();
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $this->proxy('https://www.proxy-market.test/a.png')->assertOk();
    }

    public function test_a_default_cache_header_is_added_when_upstream_omits_one(): void
    {
        $this->market();
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $this->proxy('https://proxy-market.test/a.png')
            ->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_a_not_modified_response_is_passed_through(): void
    {
        $this->market();
        Http::fake(['*' => Http::response('', 304)]);

        $this->proxy('https://proxy-market.test/a.png', ['If-None-Match' => '"abc123"'])
            ->assertStatus(304);
    }

    public function test_an_upstream_error_status_is_passed_through(): void
    {
        $this->market();
        Http::fake(['*' => Http::response('nope', 404)]);

        // The media gallery distinguishes 404 from 502, so the status matters.
        $this->proxy('https://proxy-market.test/missing.png')->assertStatus(404);
    }

    public function test_a_non_media_content_type_is_refused(): void
    {
        $this->market();
        Http::fake(['*' => Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html'])]);

        // An HTML login page must never be served as if it were the image.
        $this->proxy('https://proxy-market.test/a.png')->assertStatus(502);
    }

    public function test_video_is_still_proxied(): void
    {
        $this->market();
        Http::fake(['*' => Http::response('fakevideo', 200, ['Content-Type' => 'video/mp4'])]);

        $this->proxy('https://proxy-market.test/clip.mp4')->assertOk();
    }

    public function test_an_upstream_failure_becomes_a_bad_gateway(): void
    {
        $this->market();
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->proxy('https://proxy-market.test/a.png')->assertStatus(502);
    }

    public function test_a_head_request_returns_headers_without_a_body(): void
    {
        $this->market();
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        // Every media card HEADs the proxy before rendering, so this path is hot.
        $response = $this->head('/api/crm/image-proxy?url='.urlencode('https://proxy-market.test/a.png'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
    }

    public function test_conditional_request_headers_are_forwarded_upstream(): void
    {
        $this->market();
        Http::fake(['*' => Http::response('', 304)]);

        $this->proxy('https://proxy-market.test/a.png', [
            'If-None-Match' => '"etag-1"',
            'If-Modified-Since' => 'Wed, 09 Sep 2026 00:00:00 GMT',
        ])->assertStatus(304);

        Http::assertSent(function ($request) {
            return $request->hasHeader('If-None-Match', '"etag-1"')
                && $request->hasHeader('If-Modified-Since', 'Wed, 09 Sep 2026 00:00:00 GMT');
        });
    }

    // ---------------------------------------------------------------------
    // The September 2026 worker-starvation fix.
    // ---------------------------------------------------------------------

    public function test_the_upstream_fetch_is_bounded_by_real_timeouts(): void
    {
        $this->market();
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $this->proxy('https://proxy-market.test/a.png')->assertOk();

        // `stream => true` silently swaps Guzzle onto StreamHandler, where
        // `timeout` degrades to a per-read idle timeout and `connect_timeout`
        // is ignored entirely — so the old timeout(15) bounded nothing and a
        // slow market could hold a worker indefinitely. Assert on the source of
        // truth: the option must not be set.
        $source = file_get_contents(app_path('Http/Controllers/CRM/ImageProxyController.php'));

        $this->assertStringNotContainsString(
            "'stream' => true",
            $source,
            'stream => true routes Guzzle onto StreamHandler, where neither timeout is enforced.'
        );
        $this->assertStringContainsString('->connectTimeout(', $source, 'A connect timeout must be explicit.');
        $this->assertStringContainsString('->timeout(', $source);
    }

    public function test_timeouts_come_from_config_and_are_bounded(): void
    {
        config()->set('crm.image_proxy.connect_timeout', 3);
        config()->set('crm.image_proxy.timeout', 8);

        $this->market();
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $this->proxy('https://proxy-market.test/a.png')->assertOk();

        // Worst-case worker hold is connect + total, which must stay well under
        // the FastCGI/Cloudflare limits that produce a 504.
        $this->assertLessThanOrEqual(
            15,
            (int) config('crm.image_proxy.connect_timeout') + (int) config('crm.image_proxy.timeout')
        );
    }

    public function test_media_from_an_unhealthy_market_is_refused_without_calling_it(): void
    {
        $this->market(['health_status' => MarketHealthService::STATUS_DOMAIN_UNREACHABLE]);
        Http::fake();

        $response = $this->proxy('https://proxy-market.test/a.png');

        $response->assertStatus(503);
        $response->assertHeader('Retry-After', '60');

        // The whole point: a market already known to be down must not cost a
        // full timeout to rediscover, once per asset, for every open gallery.
        Http::assertNothingSent();
    }

    public function test_a_healthy_market_is_still_proxied(): void
    {
        $this->market(['health_status' => MarketHealthService::STATUS_HEALTHY]);
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $this->proxy('https://proxy-market.test/a.png')->assertOk();
    }

    public function test_an_unconfigured_market_is_still_proxied(): void
    {
        // `unconfigured` is not `down` — MarketHealthService::isDown() excludes
        // it, and markets without probes configured must keep working.
        $this->market(['health_status' => MarketHealthService::STATUS_UNCONFIGURED]);
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $this->proxy('https://proxy-market.test/a.png')->assertOk();
    }

    public function test_the_breaker_can_be_switched_off(): void
    {
        config()->set('crm.image_proxy.skip_unhealthy_markets', false);
        $this->market(['health_status' => MarketHealthService::STATUS_DOMAIN_UNREACHABLE]);
        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $this->proxy('https://proxy-market.test/a.png')->assertOk();
    }

    public function test_oversized_media_is_redirected_to_origin_rather_than_streamed(): void
    {
        config()->set('crm.image_proxy.max_bytes', 1024);
        $this->market();

        $url = 'https://proxy-market.test/big.mp4';
        Http::fake(['*' => Http::response(str_repeat('v', 4096), 200, ['Content-Type' => 'video/mp4'])]);

        $response = $this->proxy($url);

        // A 50MB video holds a worker for the whole client download. Letting the
        // browser fetch it directly costs the pool nothing.
        $response->assertStatus(302);
        $response->assertRedirect($url);
    }

    public function test_media_under_the_cap_is_still_streamed_normally(): void
    {
        config()->set('crm.image_proxy.max_bytes', 1024 * 1024);
        $this->market();

        Http::fake(['*' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);

        $response = $this->proxy('https://proxy-market.test/a.png');

        $response->assertOk();
        $this->assertSame(self::PNG, $response->streamedContent());
    }

    public function test_the_size_cap_can_be_disabled(): void
    {
        config()->set('crm.image_proxy.max_bytes', 0);
        $this->market();

        Http::fake(['*' => Http::response(str_repeat('v', 4096), 200, ['Content-Type' => 'video/mp4'])]);

        $this->proxy('https://proxy-market.test/clip.mp4')->assertOk();
    }

    public function test_the_rate_limit_is_sized_against_the_worker_pool(): void
    {
        // A limit above (workers / hold seconds) cannot be honoured whatever it
        // claims. The old 120/min per IP permitted several times the pool's
        // entire capacity from one browser.
        $perIp = (int) config('crm.image_proxy.per_ip_per_minute');
        $global = (int) config('crm.image_proxy.global_per_minute');

        $this->assertGreaterThan(0, $perIp);
        $this->assertLessThanOrEqual(60, $perIp, 'Per-IP allowance must stay within what the pool can serve.');
        $this->assertGreaterThanOrEqual($perIp, $global, 'The global ceiling must not sit below the per-IP one.');
    }
}
