<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\FeedbackInsightService;
use App\Services\Seo\LanguageDetector;
use App\Services\Seo\LinkCatalogService;
use App\Services\Seo\ProfileSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BioBenchmarkFixtureTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_only_synthetic_fixtures_using_the_real_prompt_builder(): void
    {
        $platform = Platform::factory()->create(['country' => 'Uganda']);
        $feedback = \Mockery::mock(FeedbackInsightService::class);
        $feedback->shouldReceive('instructionsForPlatform')->andReturn('');
        $feedback->shouldReceive('instructionsForClient')->andReturn('');
        $this->app->instance(FeedbackInsightService::class, $feedback);
        config(['services.seo_engine.generation' => []]);
        $service = app(BioGenerationService::class);
        $fixtures = [];
        foreach (array_keys(BioGenerationService::SUPPORTED_LANGUAGES) as $language) {
            $overlay = ['name' => 'Synthetic Maya', 'age' => 29, 'city' => 'Kampala', 'gender' => 'female',
                'services' => ['Massage', 'Outcall'], 'availability' => 'Outcall',
                'languages' => ['English', 'French'], 'hobbies' => 'Jazz and cooking'];
            $snapshot = app(ProfileSnapshotBuilder::class)->fromOverlayOnly($overlay, $platform->id);
            $options = (new \ReflectionMethod($service, 'generationOptions'))->invoke($service, [
                'min_words' => 60, 'max_words' => 75, 'max_characters' => 900,
                'language' => $language, 'contact_channel' => 'none', 'bio_format' => 'first_person_intro',
            ]);
            $options['resolved_bio_format'] = 'first_person_intro';
            $fixtures[] = ['language' => $language, 'profile_snapshot' => $overlay, 'generation_options' => $options,
                'system' => (new \ReflectionMethod($service, 'buildSystemPrompt'))->invoke($service, $snapshot, $options),
                'user' => (new \ReflectionMethod($service, 'buildUserPrompt'))->invoke($service, $snapshot, $options, $overlay),
                'max_tokens' => (new \ReflectionMethod($service, 'maxTokensForOptions'))->invoke($service, $options, true),
                'temperature' => $options['creativity']];
        }
        $this->assertCount(4, $fixtures);
        foreach ($fixtures as $fixture) {
            $this->assertSame(384, $fixture['max_tokens']);
            $this->assertStringContainsString('60-75 words', $fixture['system']);
        }
        if ($path = getenv('BIO_BENCHMARK_FIXTURES')) {
            file_put_contents($path, json_encode(['version' => 1, 'synthetic_only' => true, 'fixtures' => $fixtures], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
        if ($path = getenv('BIO_BENCHMARK_RESULTS')) {
            $captured = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $this->assertTrue($captured['synthetic_only']);
            $this->assertLessThanOrEqual(24, count($captured['results']));
            $catalog = \Mockery::mock(LinkCatalogService::class);
            $catalog->shouldReceive('forPlatform')->andReturn([
                ['keyword' => 'Kampala', 'url' => '/kampala/', 'category' => 'location', 'priority' => 10],
                ['keyword' => 'Massage', 'url' => '/massage-escorts/', 'category' => 'service:massage', 'priority' => 8],
            ]);
            $this->app->instance(LinkCatalogService::class, $catalog);
            config(['services.seo_engine.providers' => ['openrouter'], 'services.seo_engine.openrouter.api_key' => 'synthetic-key',
                'services.seo_engine.openrouter.model' => 'deepseek/deepseek-v3.2', 'services.seo_engine.openrouter.fallback_models' => []]);
            $current = [];
            Http::preventStrayRequests();
            Http::fake(function ($request) use (&$current) {
                $this->assertSame('https://openrouter.ai/api/v1/chat/completions', $request->url());
                $this->assertSame(384, $request['max_tokens']);

                return Http::response(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => $current['synthetic_text']]]],
                    'usage' => ['prompt_tokens' => $current['input_tokens'], 'completion_tokens' => $current['output_tokens']]]);
            });
            $quality = [];
            foreach ($captured['results'] as $current) {
                if (($current['http_status'] ?? 0) !== 200 || empty($current['synthetic_text'])) {
                    continue;
                }
                $fixture = collect($fixtures)->firstWhere('language', $current['language']);
                $result = app(BioGenerationService::class)->generate(['_interactive' => true, 'platform_id' => $platform->id,
                    'profile_snapshot' => $fixture['profile_snapshot'], 'generation_options' => $fixture['generation_options']]);
                $plain = html_entity_decode(strip_tags($result['bio_html']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $words = preg_match_all('/[\p{L}\p{N}]+(?:[\x27\x{2019}-][\p{L}\p{N}]+)*/u', $plain);
                $this->assertFalse($result['fallback_used']);
                $this->assertLessThanOrEqual(900, mb_strlen($plain));
                $quality[] = ['route' => $current['route'], 'language' => $current['language'], 'detected_language' => app(LanguageDetector::class)->detect($plain),
                    'words' => $words, 'within_requested_word_range' => $words >= 60 && $words <= 75, 'characters' => mb_strlen($plain),
                    'score' => $result['score'], 'uniqueness_score' => $result['bio_uniqueness_score'], 'ai_slop_score' => $result['ai_slop_score'],
                    'rewrite_requested' => $result['rewritten_for_uniqueness'], 'corpus_sample_size' => $result['corpus_sample_size'],
                    'has_kampala_link' => str_contains($result['bio_html'], '/kampala/'), 'processed_synthetic_html' => $result['bio_html']];
            }
            file_put_contents(getenv('BIO_BENCHMARK_QUALITY_OUTPUT'), json_encode(['synthetic_only' => true, 'network' => 'HTTP fakes only; captured vendor responses through actual sanitizer/length/uniqueness/link/scoring pipeline', 'results' => $quality], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        }
    }
}
