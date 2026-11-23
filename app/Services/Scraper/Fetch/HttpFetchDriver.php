<?php

namespace App\Services\Scraper\Fetch;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Plain HTTP fetch with the declared CRM user agent. Follows redirects manually so every
 * hop passes the SSRF guard, and returns error pages (403/429/5xx) with their bodies so
 * the blocker detector can explain them.
 */
class HttpFetchDriver implements FetchDriver
{
    public function __construct(private readonly UrlSafety $urlSafety)
    {
    }

    public function key(): string
    {
        return 'http';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function fetch(FetchRequest $request): FetchResult
    {
        $started = microtime(true);
        $url = $request->url;
        $maxRedirects = (int) config('scraper.http.max_redirects', 5);
        $maxBytes = (int) config('scraper.http.max_bytes', 6 * 1024 * 1024);

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $unsafe = $this->urlSafety->unsafeReason($url);
            if ($unsafe !== null) {
                return FetchResult::failure($request->url, $this->key(), $unsafe, $this->elapsed($started));
            }

            try {
                $response = Http::withHeaders($this->headers($request))
                    ->withoutRedirecting()
                    ->connectTimeout((int) config('scraper.http.connect_timeout', 6))
                    ->timeout(max(5, min(60, $request->timeoutSeconds)))
                    ->get($url);
            } catch (ConnectionException $exception) {
                return FetchResult::failure($request->url, $this->key(), 'Could not connect: ' . mb_substr($exception->getMessage(), 0, 180), $this->elapsed($started));
            } catch (\Throwable $exception) {
                return FetchResult::failure($request->url, $this->key(), 'Fetch failed: ' . mb_substr($exception->getMessage(), 0, 180), $this->elapsed($started));
            }

            $status = $response->status();
            $location = (string) $response->header('location');
            if ($status >= 300 && $status < 400 && $location !== '') {
                $url = $this->resolve($url, $location);
                continue;
            }

            $headers = [];
            foreach ($response->headers() as $name => $values) {
                $headers[strtolower((string) $name)] = implode(', ', (array) $values);
            }

            $body = (string) $response->body();
            $truncated = strlen($body) > $maxBytes;
            if ($truncated) {
                $body = substr($body, 0, $maxBytes);
            }

            $contentType = strtolower($headers['content-type'] ?? '');
            $error = null;
            if ($contentType !== '' && !str_contains($contentType, 'html') && !str_contains($contentType, 'xml') && !str_contains($contentType, 'json') && !str_contains($contentType, 'text/plain')) {
                $error = 'Unsupported content type: ' . $contentType;
            }

            return new FetchResult($request->url, $url, $status, $headers, $body, strlen($body), $this->elapsed($started), $this->key(), $error, null, $truncated);
        }

        return FetchResult::failure($request->url, $this->key(), 'Too many redirects.', $this->elapsed($started));
    }

    private function headers(FetchRequest $request): array
    {
        $headers = [
            'User-Agent' => $request->userAgent ?: (string) config('scraper.user_agent'),
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.5',
            'Accept-Language' => 'en;q=0.9,fr;q=0.6,*;q=0.3',
        ];

        foreach ($request->headers as $name => $value) {
            $name = trim((string) $name);
            if ($name === '' || in_array(strtolower($name), ['host', 'user-agent', 'content-length'], true)) {
                continue;
            }
            $headers[$name] = (string) $value;
        }

        if ($request->cookies !== []) {
            $pairs = [];
            foreach ($request->cookies as $cookie) {
                $name = trim((string) ($cookie['name'] ?? ''));
                if ($name !== '' && preg_match('/^[A-Za-z0-9_\-.]+$/', $name)) {
                    $pairs[] = $name . '=' . rawurlencode((string) ($cookie['value'] ?? ''));
                }
            }
            if ($pairs !== []) {
                $headers['Cookie'] = implode('; ', $pairs);
            }
        }

        return $headers;
    }

    private function resolve(string $base, string $href): string
    {
        return UrlTools::resolve($base, $href) ?? $href;
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
