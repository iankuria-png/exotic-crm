<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
use App\Services\Seo\BioGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Validates the HMAC middleware via the actual /api/wp-svc/seo/generate-bio route.
 */
class WpServiceAuthTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_KEY = 'test-shared-key-12345';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exotic_crm_sync.shared_key' => self::SHARED_KEY,
            'services.seo_engine.enabled' => true,
            'services.seo_engine.platform_allowlist' => [],
            'services.seo_engine.providers' => [],
        ]);

        // Stub the bio generator so we don't hit any LLMs in middleware tests.
        $stub = \Mockery::mock(BioGenerationService::class);
        $stub->shouldReceive('generate')->andReturn([
            'bio_html'      => '<p>stub bio</p>',
            'score'         => 50,
            'breakdown'     => ['word_count' => 0, 'links' => 0, 'completeness' => 25, 'media' => 25],
            'provider_used' => 'stub',
        ]);
        $this->app->instance(BioGenerationService::class, $stub);
    }

    public function test_rejects_request_without_headers(): void
    {
        $response = $this->postJson('/api/wp-svc/seo/generate-bio', ['x' => 1]);
        $response->assertStatus(401)
            ->assertJsonFragment(['error' => 'Missing required auth headers.']);
    }

    public function test_rejects_wrong_shared_key(): void
    {
        $platform = $this->makePlatform();
        $body = ['profile_snapshot' => ['name' => 'A']];
        $headers = $this->signHeaders($platform->id, $body, 'WRONG-KEY');

        $response = $this->postJson('/api/wp-svc/seo/generate-bio', $body, $headers);
        $response->assertStatus(401);
    }

    public function test_rejects_expired_timestamp(): void
    {
        $platform = $this->makePlatform();
        $body = ['profile_snapshot' => ['name' => 'A']];
        $oldTimestamp = time() - 1000;  // > 5 min skew
        $bodyJson = json_encode($body);
        $signature = hash_hmac('sha256', $oldTimestamp . '.' . $bodyJson, self::SHARED_KEY);

        $response = $this->withHeaders([
            'X-Exotic-CRM-Sync-Key'  => self::SHARED_KEY,
            'X-Exotic-Platform-Id'   => (string) $platform->id,
            'X-Exotic-Timestamp'     => (string) $oldTimestamp,
            'X-Exotic-Signature'     => $signature,
            'Content-Type'           => 'application/json',
        ])->postJson('/api/wp-svc/seo/generate-bio', $body);

        $response->assertStatus(401)
            ->assertJsonFragment(['error' => 'Request timestamp expired.']);
    }

    public function test_rejects_bad_signature(): void
    {
        $platform = $this->makePlatform();
        $body = ['profile_snapshot' => ['name' => 'A']];

        $response = $this->withHeaders([
            'X-Exotic-CRM-Sync-Key'  => self::SHARED_KEY,
            'X-Exotic-Platform-Id'   => (string) $platform->id,
            'X-Exotic-Timestamp'     => (string) time(),
            'X-Exotic-Signature'     => 'deadbeef',
            'Content-Type'           => 'application/json',
        ])->postJson('/api/wp-svc/seo/generate-bio', $body);

        $response->assertStatus(401)
            ->assertJsonFragment(['error' => 'Invalid signature.']);
    }

    public function test_rejects_platform_not_in_allowlist(): void
    {
        $platform = $this->makePlatform();
        config(['services.seo_engine.platform_allowlist' => [99999]]);

        $body = ['profile_snapshot' => ['name' => 'A']];
        $headers = $this->signHeaders($platform->id, $body);

        $response = $this->postJson('/api/wp-svc/seo/generate-bio', $body, $headers);
        $response->assertStatus(403);
    }

    public function test_accepts_valid_request_in_empty_allowlist(): void
    {
        $platform = $this->makePlatform();
        // empty allowlist = allow all
        config(['services.seo_engine.platform_allowlist' => []]);

        $body = ['profile_snapshot' => ['name' => 'Anna', 'city' => 'Nairobi']];
        $headers = $this->signHeaders($platform->id, $body);

        $response = $this->postJson('/api/wp-svc/seo/generate-bio', $body, $headers);
        $response->assertStatus(200)
            ->assertJsonStructure(['bio_html', 'score', 'breakdown', 'provider_used']);
    }

    public function test_accepts_valid_request_in_explicit_allowlist(): void
    {
        $platform = $this->makePlatform();
        config(['services.seo_engine.platform_allowlist' => [$platform->id]]);

        $body = ['profile_snapshot' => ['name' => 'Anna', 'city' => 'Nairobi']];
        $headers = $this->signHeaders($platform->id, $body);

        $response = $this->postJson('/api/wp-svc/seo/generate-bio', $body, $headers);
        $response->assertStatus(200);
    }

    public function test_returns_503_when_shared_key_not_configured(): void
    {
        config(['services.exotic_crm_sync.shared_key' => '']);
        $platform = $this->makePlatform();
        $body = ['profile_snapshot' => []];
        $headers = $this->signHeaders($platform->id, $body);

        $response = $this->postJson('/api/wp-svc/seo/generate-bio', $body, $headers);
        $response->assertStatus(503);
    }

    public function test_unknown_platform_id_returns_404(): void
    {
        $body = ['profile_snapshot' => []];
        $headers = $this->signHeaders(99999, $body);

        $response = $this->postJson('/api/wp-svc/seo/generate-bio', $body, $headers);
        $response->assertStatus(404);
    }

    // ------------------------------------------------------------

    private function makePlatform(): Platform
    {
        return Platform::factory()->create();
    }

    /**
     * Build the four HMAC headers for a given platform + body.
     * Mirrors the WP plugin's signing logic.
     */
    private function signHeaders(int $platformId, array $body, string $key = self::SHARED_KEY): array
    {
        $timestamp = time();
        $bodyJson  = json_encode($body);
        $signature = hash_hmac('sha256', $timestamp . '.' . $bodyJson, $key);

        return [
            'X-Exotic-CRM-Sync-Key' => $key,
            'X-Exotic-Platform-Id'  => (string) $platformId,
            'X-Exotic-Timestamp'    => (string) $timestamp,
            'X-Exotic-Signature'    => $signature,
        ];
    }
}
