<?php

namespace App\Services;

use App\Models\Platform;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class WordPressProfileUrlResolver
{
    private const CACHE_TTL_SECONDS = 21600;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; ExoticCRM/1.0; +https://exotic-online.com)';

    /**
     * @return array{
     *     wp_post_id:int|null,
     *     source:string|null,
     *     requested_url:string,
     *     effective_url:string|null,
     *     http_status:int|null,
     *     error_code:string|null
     * }
     */
    public function resolve(string $url, Platform $platform): array
    {
        $requestedUrl = trim($url);
        $context = $this->emptyContext($requestedUrl);

        if ($requestedUrl === '' || !$this->urlHostMatchesPlatform($requestedUrl, $platform)) {
            return [
                ...$context,
                'error_code' => 'host_mismatch',
            ];
        }

        $directPostId = $this->parseWpPostIdFromUrl($requestedUrl);
        if ($directPostId !== null) {
            return [
                ...$context,
                'wp_post_id' => $directPostId,
                'source' => 'query_param',
                'effective_url' => $requestedUrl,
            ];
        }

        $cacheKey = $this->cacheKey($requestedUrl, (int) $platform->id);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $resolved = $this->resolveUncached($requestedUrl, $platform);
        if ((int) ($resolved['wp_post_id'] ?? 0) > 0) {
            Cache::put($cacheKey, $resolved, self::CACHE_TTL_SECONDS);
        }

        return $resolved;
    }

    public function parseWpPostIdFromUrl(string $url): ?int
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/[?&]post_type=escort(?:&|;)?p=(\d+)/i', $trimmed, $match)) {
            return (int) $match[1];
        }

        if (preg_match('/[?&]p=(\d+)/i', $trimmed, $match)) {
            return (int) $match[1];
        }

        if (preg_match('#/(\d+)/?$#', $trimmed, $match)) {
            return (int) $match[1];
        }

        return null;
    }

    public function parseWpPostIdFromLinkHeader(string $linkHeader): ?int
    {
        $normalized = trim($linkHeader);
        if ($normalized === '') {
            return null;
        }

        if (preg_match_all('/<([^>]+)>\s*;[^,]*\brel=["\']?shortlink["\']?/i', $normalized, $matches)) {
            foreach ((array) ($matches[1] ?? []) as $link) {
                $wpPostId = $this->parseWpPostIdFromUrl((string) $link);
                if ($wpPostId !== null) {
                    return $wpPostId;
                }
            }
        }

        return null;
    }

    public function parseWpPostIdFromHtmlShortlink(string $html): ?int
    {
        $normalized = trim($html);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/<link[^>]+rel=["\']shortlink["\'][^>]+href=["\']([^"\']+)["\']/i', $normalized, $match)) {
            return $this->parseWpPostIdFromUrl((string) ($match[1] ?? ''));
        }

        if (preg_match('/<link[^>]+href=["\']([^"\']+)["\'][^>]+rel=["\']shortlink["\']/i', $normalized, $match)) {
            return $this->parseWpPostIdFromUrl((string) ($match[1] ?? ''));
        }

        return null;
    }

    public function parseWpPostIdFromHtmlMarkers(string $html): ?int
    {
        $normalized = trim($html);
        if ($normalized === '') {
            return null;
        }

        $patterns = [
            '/\bpostid-(\d+)\b/i',
            '/\bprofile_id\b[^>]*\bvalue=["\']?(\d+)["\']?/i',
            '/\bCURRENT_ID\b\s*=\s*(\d+)/i',
            '/\bpid\b\s*=\s*(\d+)/i',
            '/\bcachePurgePostId\b["\']?\s*:\s*(\d+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $match)) {
                $postId = (int) ($match[1] ?? 0);
                if ($postId > 0) {
                    return $postId;
                }
            }
        }

        return null;
    }

    private function resolveUncached(string $url, Platform $platform): array
    {
        $head = $this->safeRequest('HEAD', $url);
        $headContext = $head['context'];
        $headResponse = $head['response'];

        if ($headResponse instanceof Response) {
            $postId = $this->parseWpPostIdFromResponse($headResponse);
            if ($postId !== null) {
                return [
                    ...$headContext,
                    'wp_post_id' => $postId,
                    'source' => 'head_shortlink',
                ];
            }

            $redirectUrl = $this->sameHostRedirectUrl($headResponse, $platform);
            if ($redirectUrl !== null) {
                $redirectHead = $this->safeRequest('HEAD', $redirectUrl);
                $redirectResponse = $redirectHead['response'];

                if ($redirectResponse instanceof Response) {
                    $postId = $this->parseWpPostIdFromResponse($redirectResponse);
                    if ($postId !== null) {
                        return [
                            ...$redirectHead['context'],
                            'wp_post_id' => $postId,
                            'source' => 'head_shortlink',
                        ];
                    }
                }
            }
        }

        $get = $this->safeRequest('GET', $url);
        $getContext = $get['context'];
        $getResponse = $get['response'];

        if (!$getResponse instanceof Response) {
            return $headContext['http_status'] !== null ? $headContext : $getContext;
        }

        $postId = $this->parseWpPostIdFromResponse($getResponse);
        if ($postId !== null) {
            return [
                ...$getContext,
                'wp_post_id' => $postId,
                'source' => 'html_shortlink',
            ];
        }

        return $getContext;
    }

    /**
     * @return array{response: Response|null, context: array<string, mixed>}
     */
    private function safeRequest(string $method, string $url): array
    {
        $context = $this->emptyContext($url);

        try {
            $request = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => $method === 'HEAD'
                    ? 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1'
                    : 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
            ])
                ->withoutRedirecting()
                ->connectTimeout(2)
                ->timeout($method === 'HEAD' ? 4 : 6);

            $response = $method === 'HEAD'
                ? $request->head($url)
                : $request->get($url);

            $effectiveUri = $response->effectiveUri();
            $effectiveUrl = $effectiveUri ? (string) $effectiveUri : $url;

            return [
                'response' => $response,
                'context' => [
                    ...$context,
                    'effective_url' => $effectiveUrl,
                    'http_status' => $response->status(),
                    'error_code' => $response->successful() ? null : 'http_' . $response->status(),
                ],
            ];
        } catch (Throwable) {
            return [
                'response' => null,
                'context' => [
                    ...$context,
                    'error_code' => 'request_failed',
                ],
            ];
        }
    }

    private function parseWpPostIdFromResponse(Response $response): ?int
    {
        $effectiveUri = $response->effectiveUri();
        $effectiveUrl = $effectiveUri ? (string) $effectiveUri : '';
        $postId = $this->parseWpPostIdFromUrl($effectiveUrl);
        if ($postId !== null) {
            return $postId;
        }

        $postId = $this->parseWpPostIdFromLinkHeader((string) $response->header('link', ''));
        if ($postId !== null) {
            return $postId;
        }

        $body = (string) $response->body();
        $postId = $this->parseWpPostIdFromHtmlShortlink($body);
        if ($postId !== null) {
            return $postId;
        }

        return $this->parseWpPostIdFromHtmlMarkers($body);
    }

    private function sameHostRedirectUrl(Response $response, Platform $platform): ?string
    {
        if (!in_array($response->status(), [301, 302, 303, 307, 308], true)) {
            return null;
        }

        $location = trim((string) $response->header('location', ''));
        if ($location === '') {
            return null;
        }

        $baseUrl = (string) ($response->effectiveUri() ?: '');
        $redirectUrl = $this->resolveUrl($location, $baseUrl);

        return $this->urlHostMatchesPlatform($redirectUrl, $platform) ? $redirectUrl : null;
    }

    private function resolveUrl(string $url, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        $baseParts = parse_url($baseUrl);
        $scheme = (string) ($baseParts['scheme'] ?? 'https');
        $host = (string) ($baseParts['host'] ?? '');
        if ($host === '') {
            return $url;
        }

        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$url}";
        }

        $basePath = (string) ($baseParts['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($basePath)), '/');

        return "{$scheme}://{$host}{$directory}/{$url}";
    }

    private function urlHostMatchesPlatform(string $url, Platform $platform): bool
    {
        $host = $this->normalizeHost($url);
        if ($host === '') {
            return false;
        }

        $platformHosts = array_filter([
            $this->normalizeHost((string) ($platform->domain ?? '')),
            $this->normalizeHost((string) ($platform->wp_api_url ?? '')),
        ]);

        return in_array($host, $platformHosts, true);
    }

    private function normalizeHost(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $host = parse_url($trimmed, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = preg_replace('#^https?://#i', '', $trimmed) ?: '';
            $host = explode('/', $host)[0] ?? '';
        }

        $host = strtolower(trim($host));

        return preg_replace('#^www\.#', '', $host) ?: '';
    }

    private function cacheKey(string $url, int $platformId): string
    {
        $normalizedUrl = strtolower(trim($url));

        return 'wp-profile-url-resolution:' . $platformId . ':' . sha1($normalizedUrl);
    }

    /**
     * @return array{
     *     wp_post_id:null,
     *     source:null,
     *     requested_url:string,
     *     effective_url:null,
     *     http_status:null,
     *     error_code:null
     * }
     */
    private function emptyContext(string $url): array
    {
        return [
            'wp_post_id' => null,
            'source' => null,
            'requested_url' => $url,
            'effective_url' => null,
            'http_status' => null,
            'error_code' => null,
        ];
    }
}
