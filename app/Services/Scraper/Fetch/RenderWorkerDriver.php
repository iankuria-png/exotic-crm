<?php

namespace App\Services\Scraper\Fetch;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class RenderWorkerDriver implements FetchDriver
{
    public function key(): string
    {
        return 'render_worker';
    }

    public function isAvailable(): bool
    {
        return (bool) config('scraper.render_worker.enabled', false)
            && trim((string) config('scraper.render_worker.url')) !== ''
            && trim((string) config('scraper.render_worker.secret')) !== '';
    }

    public function fetch(FetchRequest $request): FetchResult
    {
        $started = microtime(true);
        if (! $this->isAvailable()) {
            return $this->failure($request, 'Render worker unavailable: enable it and configure its URL and secret.', $started);
        }

        $payload = [
            'url' => $request->url,
            'wait_until' => 'domcontentloaded',
            'wait_for_selector' => $request->waitForSelector,
            'timeout_ms' => max(1, min(45000, $request->timeoutSeconds * 1000)),
            'scroll_steps' => max(0, min(5, $request->scrollSteps)),
            'headers' => (object) $request->headers,
            'cookies' => $request->cookies,
            'user_agent' => $request->userAgent ?: (string) config('scraper.user_agent', 'ExoticCRMLeadBot/1.0 (+https://crm.exotic-online.com)'),
            'screenshot' => false,
        ];

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) time();
            $signature = hash_hmac('sha256', $timestamp."\n".$body, (string) config('scraper.render_worker.secret'));
            $response = Http::acceptJson()
                ->withHeaders(['X-Scraper-Timestamp' => $timestamp, 'X-Scraper-Signature' => $signature])
                ->withBody($body, 'application/json')
                ->withoutRedirecting()
                ->connectTimeout(6)
                ->timeout(max(1, (int) config('scraper.render_worker.timeout_seconds', 60)))
                ->post(rtrim((string) config('scraper.render_worker.url'), '/').'/v1/render');
        } catch (ConnectionException $exception) {
            return $this->failure($request, 'Render worker unreachable', $started);
        } catch (\Throwable $exception) {
            return $this->failure($request, 'Render worker request failed', $started);
        }

        $data = $response->json();
        if (! $response->successful() || ! is_array($data) || ($data['ok'] ?? false) !== true) {
            $code = is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'invalid_response';
            $code = in_array($code, ['timeout', 'navigation_failed', 'ssrf_blocked', 'bad_request'], true) ? $code : 'invalid_response';

            return $this->failure($request, 'Render worker error: '.$code.' (HTTP '.$response->status().')', $started);
        }

        if (! is_string($data['html'] ?? null) || ! is_string($data['final_url'] ?? null)
            || ! is_int($data['status'] ?? null) || $data['status'] < 100 || $data['status'] > 599
            || ! is_int($data['elapsed_ms'] ?? null) || $data['elapsed_ms'] < 0
            || ! is_bool($data['truncated'] ?? null)) {
            return $this->failure($request, 'Render worker error: invalid_response', $started);
        }

        $blocked = $data['blocked'] ?? null;
        if ($blocked !== null && (! is_array($blocked)
            || ! in_array($blocked['type'] ?? null, ['cloudflare_challenge', 'captcha', 'login_wall', 'http_error'], true))) {
            return $this->failure($request, 'Render worker error: invalid_response', $started);
        }

        $limit = 6 * 1024 * 1024;
        $html = mb_strcut($data['html'], 0, $limit, 'UTF-8');

        return new FetchResult(
            requestedUrl: $request->url,
            finalUrl: $data['final_url'],
            status: $data['status'],
            headers: [],
            html: $html,
            bytes: strlen($html),
            elapsedMs: $data['elapsed_ms'],
            driver: $this->key(),
            blocked: $blocked,
            truncated: $data['truncated'] || strlen($data['html']) > $limit,
        );
    }

    private function failure(FetchRequest $request, string $message, float $started): FetchResult
    {
        return new FetchResult(
            requestedUrl: $request->url,
            finalUrl: $request->url,
            status: 0,
            headers: [],
            html: '',
            bytes: 0,
            elapsedMs: (int) round((microtime(true) - $started) * 1000),
            driver: $this->key(),
            error: $message,
        );
    }
}
