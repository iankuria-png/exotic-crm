<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Services\Pbn\PbnSeedBioService;
use App\Services\Seo\BioGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PbnSeedBioFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_BIO = 'Original advertiser copy that must not be republished verbatim across the network.';

    private function client(): Client
    {
        $platform = Platform::factory()->create();

        return Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 4321,
            'name' => 'Fallback Demo',
            'city' => 'Kampala',
        ]);
    }

    private function serviceReturning(array $generated): PbnSeedBioService
    {
        $generator = Mockery::mock(BioGenerationService::class);
        $generator->shouldReceive('generate')->andReturn($generated);
        $this->app->instance(BioGenerationService::class, $generator);

        return $this->app->make(PbnSeedBioService::class);
    }

    /** A clean generation is used as-is and reports which provider produced it. */
    public function test_a_successful_generation_is_used(): void
    {
        $service = $this->serviceReturning([
            'bio_html' => '<p>Freshly written copy for this market.</p>',
            'fallback_used' => false,
            'provider_used' => 'claude',
            'usage' => ['estimated_cost_usd' => 0.0009],
        ]);

        $result = $service->rewrite(self::SOURCE_BIO, $this->client(), ['bio_mode' => 'rewrite', 'bio_on_failure' => 'template']);

        $this->assertSame(PbnSeedBioService::RESULT_REWRITTEN, $result['result']);
        $this->assertSame('claude', $result['provider']);
        $this->assertStringContainsString('Freshly written', $result['text']);
    }

    /**
     * When every provider fails the SEO engine substitutes its template. A
     * batch that accepts it publishes it — different text at no cost, which
     * beats republishing the source.
     */
    public function test_template_fallback_is_published_when_the_policy_accepts_it(): void
    {
        $service = $this->serviceReturning([
            'bio_html' => '<p>Deterministic template copy.</p>',
            'fallback_used' => true,
            'provider_used' => 'template_fallback',
            'usage' => ['estimated_cost_usd' => 0.0],
        ]);

        $result = $service->rewrite(self::SOURCE_BIO, $this->client(), ['bio_mode' => 'rewrite', 'bio_on_failure' => 'template']);

        $this->assertSame(PbnSeedBioService::RESULT_TEMPLATE, $result['result']);
        $this->assertStringContainsString('Deterministic template', $result['text']);
    }

    /** A batch that does not accept the template keeps the source text instead. */
    public function test_verbatim_policy_rejects_the_template_and_keeps_the_source(): void
    {
        $service = $this->serviceReturning([
            'bio_html' => '<p>Deterministic template copy.</p>',
            'fallback_used' => true,
            'usage' => ['estimated_cost_usd' => 0.0],
        ]);

        $result = $service->rewrite(self::SOURCE_BIO, $this->client(), ['bio_mode' => 'rewrite', 'bio_on_failure' => 'verbatim']);

        $this->assertSame(PbnSeedBioService::RESULT_FALLBACK, $result['result']);
        $this->assertSame(self::SOURCE_BIO, $result['text']);
        $this->assertStringContainsString('does not accept the template', $result['note']);
    }

    /** The attention policy holds the profile rather than publishing anything. */
    public function test_attention_policy_holds_the_item(): void
    {
        $service = $this->serviceReturning([
            'bio_html' => '<p>Deterministic template copy.</p>',
            'fallback_used' => true,
            'usage' => ['estimated_cost_usd' => 0.0],
        ]);

        $this->expectException(\RuntimeException::class);

        $service->rewrite(self::SOURCE_BIO, $this->client(), ['bio_mode' => 'rewrite', 'bio_on_failure' => 'attention']);
    }

    /** A generation that returns the source unchanged is not a rewrite. */
    public function test_unchanged_output_is_treated_as_a_failure(): void
    {
        $service = $this->serviceReturning([
            'bio_html' => self::SOURCE_BIO,
            'fallback_used' => false,
            'provider_used' => 'claude',
            'usage' => ['estimated_cost_usd' => 0.0005],
        ]);

        $result = $service->rewrite(self::SOURCE_BIO, $this->client(), ['bio_mode' => 'rewrite', 'bio_on_failure' => 'verbatim']);

        $this->assertSame(PbnSeedBioService::RESULT_FALLBACK, $result['result']);
        $this->assertStringContainsString('unchanged', $result['note']);
    }

    /** Verbatim mode never calls the generator at all. */
    public function test_verbatim_mode_skips_generation(): void
    {
        $generator = Mockery::mock(BioGenerationService::class);
        $generator->shouldNotReceive('generate');
        $this->app->instance(BioGenerationService::class, $generator);

        $result = $this->app->make(PbnSeedBioService::class)
            ->rewrite(self::SOURCE_BIO, $this->client(), ['bio_mode' => 'verbatim']);

        $this->assertSame(PbnSeedBioService::RESULT_SKIPPED, $result['result']);
        $this->assertSame(self::SOURCE_BIO, $result['text']);
    }
}
