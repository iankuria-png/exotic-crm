<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\Llm\Adapters\GeminiAdapter;
use App\Services\Seo\Llm\Adapters\OpenAiAdapter;
use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use App\Services\Seo\LinkCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BioGenerationOverridesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.seo_engine.providers' => ['openai', 'gemini'],
            'services.seo_engine.scorer_weights' => [
                'word_count' => 25,
                'links' => 25,
                'completeness' => 25,
                'media' => 25,
            ],
        ]);

        $catalog = \Mockery::mock(LinkCatalogService::class);
        $catalog->shouldReceive('forPlatform')->andReturn([]);
        $this->app->instance(LinkCatalogService::class, $catalog);
    }

    public function test_generation_options_override_provider_order_and_scorer_weights(): void
    {
        $platform = Platform::factory()->create();
        $this->app->instance(OpenAiAdapter::class, $this->fakeAdapter('openai', shouldFail: true));
        $this->app->instance(GeminiAdapter::class, $this->fakeAdapter('gemini', text: str_repeat('rafiki ', 60)));

        $payload = [
            'platform_id' => $platform->id,
            'profile_snapshot' => [
                'name' => 'Amina',
                'city' => 'Nairobi',
                'services' => ['GFE'],
                'languages' => ['Swahili'],
                'media_summary' => ['image_count' => 3, 'video_count' => 0, 'has_main_image' => true],
            ],
        ];
        $default = app(BioGenerationService::class)->generate($payload);

        $result = app(BioGenerationService::class)->generate([
            'platform_id' => $platform->id,
            'generation_options' => [
                'language' => 'sw',
                'providers_order' => ['openai', 'gemini'],
                'scorer_weights' => [
                    'word_count' => 10,
                    'links' => 10,
                    'completeness' => 60,
                    'media' => 20,
                ],
            ],
        ] + $payload);

        $this->assertSame('gemini', $result['provider_used']);
        $this->assertSame('sw', $result['language_used']);
        $this->assertFalse($result['fallback_used']);
        $this->assertNotSame($default['score'], $result['score']);
        $this->assertSame(20, $result['breakdown']['completeness']);
    }

    public function test_omitting_overrides_preserves_existing_generation_behavior(): void
    {
        $platform = Platform::factory()->create();
        config(['services.seo_engine.providers' => []]);

        $service = app(BioGenerationService::class);
        $payload = [
            'platform_id' => $platform->id,
            'profile_snapshot' => [
                'name' => 'Nia',
                'city' => 'Mombasa',
            ],
        ];

        $withoutOverrides = $service->generate($payload);
        $withEmptyOverrides = $service->generate($payload + ['generation_options' => []]);

        $this->assertSame($withoutOverrides['bio_html'], $withEmptyOverrides['bio_html']);
        $this->assertSame($withoutOverrides['score'], $withEmptyOverrides['score']);
        $this->assertSame($withoutOverrides['breakdown'], $withEmptyOverrides['breakdown']);
        $this->assertSame('en', $withoutOverrides['language_used']);
        $this->assertTrue($withoutOverrides['fallback_used']);
    }

    private function fakeAdapter(string $name, bool $shouldFail = false, string $text = ''): LlmClient
    {
        return new class($name, $shouldFail, $text) implements LlmClient {
            public function __construct(
                private readonly string $name,
                private readonly bool $shouldFail,
                private readonly string $text,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function generate(string $system, string $user, array $opts = []): LlmResponse
            {
                if ($this->shouldFail) {
                    throw new \RuntimeException("{$this->name} failed");
                }

                return new LlmResponse(
                    text: $this->text !== '' ? $this->text : str_repeat('word ', 60),
                    inputTokens: 24,
                    outputTokens: 18,
                );
            }
        };
    }
}
