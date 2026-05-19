<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\LinkCatalogService;
use App\Services\Seo\LinkInjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the full pipeline:
 *   snapshot → LLM (forced fail) → template fallback → link inject → score.
 * No external HTTP calls (no providers configured).
 */
class BioGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No providers → waterfall throws AllProvidersFailedException → template fallback runs
        config([
            'services.seo_engine.providers' => [],
            'services.seo_engine.scorer_weights' => [
                'word_count'   => 25,
                'links'        => 25,
                'completeness' => 25,
                'media'        => 25,
            ],
        ]);

        // Stub the link catalog so we don't hit WP
        $stub = \Mockery::mock(LinkCatalogService::class);
        $stub->shouldReceive('forPlatform')->andReturn([]);
        $this->app->instance(LinkCatalogService::class, $stub);
    }

    public function test_generates_bio_via_template_fallback_when_no_providers(): void
    {
        $platform = Platform::factory()->create();
        $service = app(BioGenerationService::class);

        $result = $service->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => [
                'name' => 'Anna',
                'age'  => 25,
                'city' => 'Nairobi',
                'service' => 'GFE',
                'gender' => 'female',
                'ethnicity' => 'African',
                'build' => 'slim',
                'hair_color' => 'black',
                'height' => '170cm',
            ],
        ]);

        $this->assertArrayHasKey('bio_html', $result);
        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('breakdown', $result);
        $this->assertSame('template_fallback', $result['provider_used']);
        $this->assertStringContainsString('Anna', $result['bio_html']);
        $this->assertStringNotContainsString('170cm', $result['bio_html']);
        $this->assertStringNotContainsString('height', strtolower($result['bio_html']));
    }

    public function test_generation_continues_when_link_injection_fails(): void
    {
        $platform = Platform::factory()->create();

        $catalog = \Mockery::mock(LinkCatalogService::class);
        $catalog->shouldReceive('forPlatform')->andReturn([
            ['keyword' => 'Nairobi', 'url' => '/escorts/nairobi', 'category' => 'location', 'priority' => 10],
        ]);
        $this->app->instance(LinkCatalogService::class, $catalog);

        $injector = \Mockery::mock(LinkInjector::class);
        $injector->shouldReceive('inject')->andThrow(new \Error('Class "DOMDocument" not found'));
        $this->app->instance(LinkInjector::class, $injector);

        $service = app(BioGenerationService::class);

        $result = $service->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Nia', 'city' => 'Nairobi'],
        ]);

        $this->assertArrayHasKey('bio_html', $result);
        $this->assertSame('template_fallback', $result['provider_used']);
        $this->assertStringContainsString('Nia', $result['bio_html']);
    }

    public function test_returns_breakdown_with_four_components(): void
    {
        $platform = Platform::factory()->create();
        $service = app(BioGenerationService::class);

        $result = $service->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'Bea', 'city' => 'Mombasa'],
        ]);

        $this->assertArrayHasKey('word_count', $result['breakdown']);
        $this->assertArrayHasKey('links', $result['breakdown']);
        $this->assertArrayHasKey('completeness', $result['breakdown']);
        $this->assertArrayHasKey('media', $result['breakdown']);
    }

    public function test_score_is_integer_between_0_and_100(): void
    {
        $platform = Platform::factory()->create();
        $service = app(BioGenerationService::class);

        $result = $service->generate([
            'platform_id' => $platform->id,
            'profile_snapshot' => ['name' => 'X', 'city' => 'Y'],
        ]);

        $this->assertIsInt($result['score']);
        $this->assertGreaterThanOrEqual(0, $result['score']);
        $this->assertLessThanOrEqual(100, $result['score']);
    }
}
