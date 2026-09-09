<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Services\MarketHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proxies market media so Cloudflare hotlink protection does not block it.
 *
 * The hard constraint here is that EVERY proxied asset occupies one PHP-FPM
 * child for the duration of the upstream fetch, and production runs a small
 * pool. On 8-9 September 2026 that pool was repeatedly exhausted: workers sat
 * at near-zero CPU, blocked in socket reads, while a trivial standalone PHP
 * script could not execute and Cloudflare returned 504s across every route.
 *
 * The cause was that `stream => true` routes Guzzle away from the cURL handler
 * and onto StreamHandler (see GuzzleHttp\Utils::chooseHandler and
 * Handler\Proxy::wrapStreaming). Under StreamHandler:
 *
 *   - `timeout` becomes the PHP stream context timeout, which is a PER-READ
 *     idle timeout, NOT a cap on the whole transfer. An upstream trickling one
 *     byte inside each window holds the worker indefinitely.
 *   - `connect_timeout` is ignored outright, falling back to
 *     `default_socket_timeout` (60s).
 *
 * So the `timeout(15)` this class used to carry bounded nothing. It now fetches
 * through cURL with an explicit connect and total timeout, both enforced, and
 * declines work it should not be doing at all.
 */
class ImageProxyController extends Controller
{
    private const ALLOWED_HOSTS_TTL = 300;

    /** Health lookups are per-request hot; keep them off the database. */
    private const UNHEALTHY_HOSTS_TTL = 60;

    public function show(Request $request): StreamedResponse|\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
    {
        $rawUrl = trim((string) $request->query('url', ''));

        if ($rawUrl === '') {
            return response('Missing url parameter.', 400);
        }

        $parsed = parse_url($rawUrl);
        $scheme = strtolower($parsed['scheme'] ?? '');
        $host   = strtolower($parsed['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return response('Invalid URL.', 400);
        }

        if (!$this->isAllowedHost($host)) {
            return response('Forbidden: host not in allowed platform list.', 403);
        }

        $upstreamHeaders = [];
        if ($request->hasHeader('If-None-Match')) {
            $upstreamHeaders['If-None-Match'] = $request->header('If-None-Match');
        }
        if ($request->hasHeader('If-Modified-Since')) {
            $upstreamHeaders['If-Modified-Since'] = $request->header('If-Modified-Since');
        }

        $method = $request->isMethod('head') ? 'HEAD' : 'GET';

        // A market the CRM already knows is unreachable would cost a full
        // timeout to rediscover, once per asset, across every open gallery.
        // MarketHealthService maintains this continuously; use it.
        if ($this->isUnhealthyHost($host)) {
            return response('Upstream market is currently unhealthy.', 503, [
                'Retry-After' => '60',
                'X-Proxied-By' => 'ExoticCRM',
            ]);
        }

        try {
            $upstream = Http::withHeaders(array_merge([
                'User-Agent' => 'ExoticCRM-ImageProxy/1.0',
                'Accept'     => 'image/*,video/*,*/*;q=0.8',
            ], $upstreamHeaders))
                // NOTE: no `stream` option. Setting it silently swaps Guzzle onto
                // StreamHandler, where neither timeout below is honoured as
                // written. Buffering to a temp file keeps memory flat while
                // leaving cURL in charge of the clock.
                ->withOptions([
                    'allow_redirects' => ['max' => 3, 'strict' => true],
                    'sink' => $this->temporarySink(),
                ])
                ->connectTimeout($this->connectTimeout())
                ->timeout($this->timeout())
                ->send($method, $rawUrl);
        } catch (\Throwable $e) {
            Log::warning('Image proxy upstream fetch failed.', [
                'host' => $host,
                'error' => $e->getMessage(),
            ]);

            return response('Upstream fetch failed.', 502);
        }

        $status = $upstream->status();

        if ($status === 304) {
            return response('', 304);
        }

        if ($status >= 400) {
            return response('Upstream returned ' . $status . '.', $status);
        }

        $contentType = $upstream->header('Content-Type') ?: 'application/octet-stream';
        if (!str_starts_with(strtolower($contentType), 'image/') && !str_starts_with(strtolower($contentType), 'video/')) {
            return response('Upstream response is not an image or video.', 502);
        }

        $passthrough = ['Content-Type', 'Cache-Control', 'ETag', 'Last-Modified', 'Content-Length', 'Expires'];
        $responseHeaders = ['X-Proxied-By' => 'ExoticCRM'];
        foreach ($passthrough as $header) {
            $value = $upstream->header($header);
            if ($value !== '' && $value !== null) {
                $responseHeaders[$header] = $value;
            }
        }

        if (!isset($responseHeaders['Cache-Control'])) {
            $responseHeaders['Cache-Control'] = 'public, max-age=3600';
        }

        if ($request->isMethod('head')) {
            return response('', $status, $responseHeaders);
        }

        $body = $upstream->toPsrResponse()->getBody();

        // Buffering to a sink leaves the stream at EOF, so it must be rewound
        // before anything is read back out of it. Without this the proxy
        // returns a 200 with a zero-byte body — every image on the page blank.
        if ($body->isSeekable()) {
            $body->rewind();
        }

        // Oversized media is sent back to origin rather than pushed through a
        // worker. A 50MB profile video occupies a child for the whole client
        // download, which the pool cannot absorb; the browser fetching it
        // directly costs us nothing. Images are orders of magnitude below the
        // cap, so the common path is untouched.
        $maxBytes = $this->maxBytes();
        $size = $body->getSize();

        if ($maxBytes > 0 && $size !== null && $size > $maxBytes) {
            Log::info('Image proxy redirected oversized asset to origin.', [
                'host' => $host,
                'bytes' => $size,
            ]);

            return redirect()->away($rawUrl, 302);
        }

        return new StreamedResponse(function () use ($body) {
            while (!$body->eof()) {
                echo $body->read(8192);
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
            }
        }, $status, $responseHeaders);
    }

    private function connectTimeout(): int
    {
        return max(1, (int) config('crm.image_proxy.connect_timeout', 3));
    }

    private function timeout(): int
    {
        return max(1, (int) config('crm.image_proxy.timeout', 8));
    }

    private function maxBytes(): int
    {
        return max(0, (int) config('crm.image_proxy.max_bytes', 8 * 1024 * 1024));
    }

    /**
     * A temp-file sink so a large asset never lands in the worker's memory.
     */
    private function temporarySink()
    {
        return fopen('php://temp/maxmemory:262144', 'w+b');
    }

    /**
     * Hosts belonging to markets the CRM currently considers unreachable.
     */
    private function isUnhealthyHost(string $host): bool
    {
        if (! config('crm.image_proxy.skip_unhealthy_markets', true)) {
            return false;
        }

        try {
            $unhealthy = Cache::remember(
                'image_proxy_unhealthy_hosts',
                self::UNHEALTHY_HOSTS_TTL,
                fn (): array => $this->buildUnhealthyHosts()
            );
        } catch (\Throwable) {
            // Never let a cache failure block media that would otherwise load.
            return false;
        }

        return in_array($host, $unhealthy, true);
    }

    /**
     * @return array<int, string>
     */
    private function buildUnhealthyHosts(): array
    {
        $health = app(MarketHealthService::class);
        $hosts = [];

        Platform::query()
            ->whereNotNull('health_status')
            ->get(['domain', 'wp_api_url', 'health_status'])
            ->each(function (Platform $platform) use (&$hosts, $health): void {
                if (! $health->isDown($platform->health_status)) {
                    return;
                }

                foreach ($this->hostsFor($platform) as $host) {
                    $hosts[] = $host;
                }
            });

        return array_values(array_unique($hosts));
    }

    private function isAllowedHost(string $host): bool
    {
        $allowed = Cache::remember('image_proxy_allowed_hosts', self::ALLOWED_HOSTS_TTL, function () {
            return $this->buildAllowedHosts();
        });

        return in_array($host, $allowed, true);
    }

    private function buildAllowedHosts(): array
    {
        $hosts = [];

        Platform::query()
            ->where(function ($q) {
                $q->whereNotNull('domain')->orWhereNotNull('wp_api_url');
            })
            ->get(['domain', 'wp_api_url'])
            ->each(function (Platform $p) use (&$hosts) {
                foreach ($this->hostsFor($p) as $host) {
                    $hosts[] = $host;
                }
            });

        return array_values(array_unique($hosts));
    }

    /**
     * Every hostname a market can legitimately serve media from.
     *
     * Shared by the allowlist and the health breaker so the two can never
     * disagree about which host belongs to which market.
     *
     * @return array<int, string>
     */
    private function hostsFor(Platform $platform): array
    {
        $hosts = [];

        $add = static function (?string $candidate) use (&$hosts): void {
            if ($candidate === null || trim($candidate) === '') {
                return;
            }

            $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
            if ($host === '') {
                return;
            }

            $hosts[] = $host;
            if (! str_starts_with($host, 'www.')) {
                $hosts[] = 'www.' . $host;
            }
        };

        if (! empty($platform->wp_api_url)) {
            $bare = preg_replace('#/wp-json/.*$#', '', (string) $platform->wp_api_url);
            $add(rtrim((string) $bare, '/'));
        }

        if (! empty($platform->domain)) {
            $domain = trim((string) $platform->domain);
            $add(str_starts_with($domain, 'http') ? $domain : 'https://' . $domain);
        }

        return array_values(array_unique($hosts));
    }
}
