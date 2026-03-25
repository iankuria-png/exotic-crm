<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImageProxyController extends Controller
{
    private const ALLOWED_HOSTS_TTL = 300;

    public function show(Request $request): StreamedResponse|\Illuminate\Http\Response
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

        try {
            $upstream = Http::withHeaders(array_merge([
                'User-Agent' => 'ExoticCRM-ImageProxy/1.0',
                'Accept'     => 'image/*,*/*;q=0.8',
            ], $upstreamHeaders))
                ->withOptions(['stream' => true, 'allow_redirects' => true])
                ->timeout(15)
                ->get($rawUrl);
        } catch (\Throwable $e) {
            return response('Upstream fetch failed.', 502);
        }

        $status = $upstream->status();

        if ($status === 304) {
            return response('', 304);
        }

        if ($status >= 400) {
            return response('Upstream returned ' . $status . '.', 502);
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

        $body = $upstream->toPsrResponse()->getBody();

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
                if (!empty($p->wp_api_url)) {
                    $bare = preg_replace('#/wp-json/.*$#', '', (string) $p->wp_api_url);
                    $h = strtolower(parse_url(rtrim($bare, '/'), PHP_URL_HOST) ?? '');
                    if ($h !== '') {
                        $hosts[] = $h;
                        if (!str_starts_with($h, 'www.')) {
                            $hosts[] = 'www.' . $h;
                        }
                    }
                }

                if (!empty($p->domain)) {
                    $d = trim((string) $p->domain);
                    $prefixed = str_starts_with($d, 'http') ? $d : 'https://' . $d;
                    $h = strtolower(parse_url($prefixed, PHP_URL_HOST) ?? '');
                    if ($h !== '') {
                        $hosts[] = $h;
                        if (!str_starts_with($h, 'www.')) {
                            $hosts[] = 'www.' . $h;
                        }
                    }
                }
            });

        return array_values(array_unique($hosts));
    }
}
