<?php

namespace Tests\Unit\Scraper;

use App\Services\Scraper\Fetch\FetchRequest;
use App\Services\Scraper\Fetch\RenderWorkerDriver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RenderWorkerDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('scraper.render_worker', [
            'enabled' => true,
            'url' => 'https://render.example.test/',
            'secret' => 'fixture-secret-of-at-least-32-characters',
            'timeout_seconds' => 60,
        ]);
        Http::preventStrayRequests();
    }

    private function workerResponse(array $overrides = []): array
    {
        return array_replace([
            'ok' => true,
            'final_url' => 'https://directory.example.test/page/2',
            'status' => 200,
            'title' => 'Directory',
            'html' => '<h1>Rendered listing</h1>',
            'bytes' => 25,
            'elapsed_ms' => 4100,
            'truncated' => false,
            'blocked' => null,
            'screenshot_jpeg_base64' => null,
        ], $overrides);
    }

    public function test_signs_exact_wire_bytes_and_maps_rendered_result(): void
    {
        Http::fake(['https://render.example.test/v1/render' => Http::response($this->workerResponse())]);
        $request = new FetchRequest(
            url: 'https://directory.example.test/é',
            headers: ['X-Consent' => 'yes'],
            cookies: [['name' => 'consent', 'value' => 'yes', 'domain' => 'directory.example.test']],
            timeoutSeconds: 80,
            waitForSelector: '.card',
            scrollSteps: 8,
            userAgent: 'ExoticCRMLeadBot/1.0 fixture',
        );
        $driver = new RenderWorkerDriver;
        $result = $driver->fetch($request);

        $this->assertTrue($driver->isAvailable());
        $this->assertSame('render_worker', $driver->key());
        $this->assertTrue($result->ok());
        $this->assertSame($request->url, $result->requestedUrl);
        $this->assertSame('https://directory.example.test/page/2', $result->finalUrl);
        $this->assertSame(200, $result->status);
        $this->assertSame('<h1>Rendered listing</h1>', $result->html);
        $this->assertSame(strlen($result->html), $result->bytes);
        $this->assertSame(4100, $result->elapsedMs);
        $this->assertSame('render_worker', $result->driver);
        $this->assertSame([], $result->headers);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $sent) use ($request) {
            $timestamp = $sent->header('X-Scraper-Timestamp')[0];
            $expected = hash_hmac('sha256', $timestamp."\n".$sent->body(), config('scraper.render_worker.secret'));
            $data = json_decode($sent->body(), true, 512, JSON_THROW_ON_ERROR);

            return $sent->method() === 'POST'
                && abs(time() - (int) $timestamp) <= 2
                && $sent->header('X-Scraper-Signature')[0] === $expected
                && $sent->hasHeader('Content-Type', 'application/json')
                && $data['url'] === $request->url
                && $data['headers'] === $request->headers
                && $data['cookies'] === $request->cookies
                && $data['timeout_ms'] === 45000
                && $data['scroll_steps'] === 5
                && $data['wait_for_selector'] === '.card'
                && $data['user_agent'] === $request->userAgent
                && $data['screenshot'] === false;
        });
    }

    public function test_empty_headers_encode_as_object_and_default_identity_is_declared(): void
    {
        Http::fake(['*' => Http::response($this->workerResponse())]);
        (new RenderWorkerDriver)->fetch(new FetchRequest('https://directory.example.test'));
        Http::assertSent(function (Request $sent) {
            $data = json_decode($sent->body(), false, 512, JSON_THROW_ON_ERROR);

            return $data->headers instanceof \stdClass
                && $data->user_agent === config('scraper.user_agent')
                && $data->timeout_ms === 25000;
        });
    }

    public function test_block_hint_status_and_truncation_are_preserved(): void
    {
        $blocked = ['type' => 'cloudflare_challenge', 'detail' => 'Human browser capture required.'];
        Http::fake(['*' => Http::response($this->workerResponse(['status' => 403, 'blocked' => $blocked, 'truncated' => true]))]);
        $result = (new RenderWorkerDriver)->fetch(new FetchRequest('https://directory.example.test'));
        $this->assertSame($blocked, $result->blocked);
        $this->assertSame(403, $result->status);
        $this->assertTrue($result->truncated);
        $this->assertNotEmpty($result->html);
        $this->assertNull($result->error);
        $this->assertFalse($result->ok());
    }

    public function test_disabled_missing_url_or_missing_secret_is_unavailable_without_http(): void
    {
        Http::fake();
        $driver = new RenderWorkerDriver;
        foreach (['enabled' => false, 'url' => '', 'secret' => '  '] as $key => $value) {
            $before = config('scraper.render_worker.'.$key);
            config()->set('scraper.render_worker.'.$key, $value);
            $this->assertFalse($driver->isAvailable());
            $result = $driver->fetch(new FetchRequest('https://directory.example.test'));
            $this->assertStringContainsString('Render worker unavailable', $result->error);
            $this->assertSame('render_worker', $result->driver);
            config()->set('scraper.render_worker.'.$key, $before);
        }
        Http::assertNothingSent();
    }

    public function test_unreachable_worker_is_explicit_and_never_retries_or_falls_back(): void
    {
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('fixture contains private infrastructure details');
        });
        $result = (new RenderWorkerDriver)->fetch(new FetchRequest('https://directory.example.test'));
        $this->assertSame('Render worker unreachable', $result->error);
        $this->assertFalse($result->ok());
        $this->assertSame(0, $result->status);
        $this->assertSame(1, $attempts);
    }

    public function test_worker_error_is_a_fetch_error_with_one_request(): void
    {
        Http::fake(['*' => Http::response(['ok' => false, 'error' => 'ssrf_blocked'], 403)]);
        $result = (new RenderWorkerDriver)->fetch(new FetchRequest('http://127.0.0.1'));
        $this->assertSame('Render worker error: ssrf_blocked (HTTP 403)', $result->error);
        $this->assertFalse($result->ok());
        Http::assertSentCount(1);
    }

    public function test_malformed_success_response_is_a_fetch_error(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'html' => []])]);
        $result = (new RenderWorkerDriver)->fetch(new FetchRequest('https://directory.example.test'));
        $this->assertSame('Render worker error: invalid_response', $result->error);
    }

    public function test_local_html_ceiling_does_not_trust_worker_bytes(): void
    {
        $limit = 6 * 1024 * 1024;
        Http::fake(['*' => Http::response($this->workerResponse(['html' => str_repeat('a', $limit - 1).'😀', 'bytes' => 1]))]);
        $result = (new RenderWorkerDriver)->fetch(new FetchRequest('https://directory.example.test'));
        $this->assertSame($limit - 1, $result->bytes);
        $this->assertSame(strlen($result->html), $result->bytes);
        $this->assertTrue($result->truncated);
    }
}
