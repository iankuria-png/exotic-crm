<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\BioUniquenessAnalyzer;
use App\Services\Seo\LinkCatalogService;
use App\Services\Seo\Llm\BioGenerationRun;
use App\Services\Seo\SeoScorer;
use App\Services\WpSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class InteractiveBioGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['services.seo_engine.providers' => ['openrouter'], 'services.seo_engine.generation' => [],
            'services.seo_engine.openrouter.api_key' => 'synthetic-key',
            'services.seo_engine.openrouter.model' => 'deepseek/deepseek-v4.1-flash-1017',
            'services.seo_engine.openrouter.fallback_models' => ['mistralai/mistral-medium-3.1']]);
    }

    private function catalogStub(): void
    {
        $catalog = \Mockery::mock(LinkCatalogService::class);
        $catalog->shouldReceive('forPlatform')->andReturn([]);
        $this->app->instance(LinkCatalogService::class, $catalog);
    }

    private function good(): array
    {
        return ['model' => 'actual-model', 'choices' => [['finish_reason' => 'stop', 'message' => ['content' => 'Kampala evenings sound better with jazz. I enjoy cooking and offer massage on outcall.']]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 35]];
    }

    private function params(int $id): array
    {
        return ['_interactive' => true, 'platform_id' => $id,
            'profile_snapshot' => ['name' => 'Synthetic', 'city' => 'Kampala'],
            'generation_options' => ['min_words' => 60, 'max_words' => 75]];
    }

    public function test_two_attempts_preserve_first_usable_draft_and_expose_skipped_rewrite(): void
    {
        $this->catalogStub();
        $analyzer = \Mockery::mock(BioUniquenessAnalyzer::class);
        $analyzer->shouldReceive('analyze')->andReturn(['overuse_score' => 80, 'overuse_flags' => [], 'corpus_sample_size' => 5,
            'ai_slop_score' => 60, 'ai_slop_flags' => [], 'bio_uniqueness_score' => 30]);
        $analyzer->shouldReceive('shouldRewrite')->andReturn(true);
        $this->app->instance(BioUniquenessAnalyzer::class, $analyzer);
        Http::fake(['*' => Http::sequence()->push([], 503)->push($this->good())]);
        $platform = Platform::factory()->create();
        $result = app(BioGenerationService::class)->generate($this->params($platform->id));
        Http::assertSentCount(2);
        $this->assertFalse($result['fallback_used']);
        $this->assertFalse($result['rewritten_for_uniqueness']);
        $this->assertSame('request_budget', $result['rewrite_skipped_reason']);
        $this->assertSame(30, $result['bio_uniqueness_score']);
        $this->assertSame('actual-model', $result['model_used']);
        $this->assertStringContainsString('Kampala', $result['bio_html']);
        Http::assertSent(fn ($r) => $r['max_tokens'] === 384);
    }

    public function test_telemetry_is_finalized_when_scoring_fails_and_error_text_is_safe(): void
    {
        $this->catalogStub();
        $scorer = \Mockery::mock(SeoScorer::class);
        $scorer->shouldReceive('score')->andThrow(new \RuntimeException('secret-credential private-profile'));
        $this->app->instance(SeoScorer::class, $scorer);
        $summary = null;
        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('info')->once()->withArgs(function ($name, $context) use (&$summary) {
            $summary = $context;

            return $name === 'seo.bio_performance';
        });
        Log::shouldReceive('channel')->with('seo_performance')->once()->andReturn($logger);
        Http::fake(fn () => Http::response($this->good()));
        $platform = Platform::factory()->create();
        try {
            app(BioGenerationService::class)->generate($this->params($platform->id));
            $this->fail('Expected scoring failure.');
        } catch (\RuntimeException $error) {
            $this->assertStringNotContainsString('secret-credential', $error->getMessage());
        }
        $this->assertSame('failed', $summary['status']);
        $this->assertSame('scoring', $summary['failed_stage']);
        $this->assertStringNotContainsString('private-profile', json_encode($summary));
    }

    public function test_cold_catalogue_uses_one_read_and_recovers_after_short_failure_backoff(): void
    {
        $platform = Platform::factory()->create();
        $n = 0;
        $failRefresh = false;
        Http::fake(function () use (&$n, &$failRefresh) {
            $n++;

            return $n === 1 || $failRefresh ? Http::response(['private' => 'secret'], 503)
                : Http::response(['locations' => [['name' => 'Kampala', 'url' => '/kampala/']], 'attribute_pages' => [['keyword' => 'Massage', 'url' => '/massage-escorts/']]]);
        });
        $service = app(LinkCatalogService::class);
        $first = $service->forPlatform($platform->id, new BioGenerationRun);
        $this->assertNotEmpty($first);
        $this->assertNull(Cache::get('seo_link_catalog_platform_'.$platform->id));
        $service->forPlatform($platform->id, new BioGenerationRun);
        $this->assertSame(1, $n);
        $this->travel(16)->seconds();
        $recovered = $service->forPlatform($platform->id, new BioGenerationRun);
        $this->assertNotNull(collect($recovered)->firstWhere('keyword', 'Kampala'));
        $service->forPlatform($platform->id, new BioGenerationRun);
        $this->assertSame(2, $n);
        $this->travel(61)->minutes();
        // A warm last-good catalogue survives a failed refresh.
        $failRefresh = true;
        $stale = $service->forPlatform($platform->id, new BioGenerationRun);
        $this->assertNotNull(collect($stale)->firstWhere('keyword', 'Kampala'));
    }

    public function test_bounded_wp_clone_preserves_headers_and_skips_expired_reads(): void
    {
        $platform = Platform::factory()->create();
        config(['services.exotic_crm_sync.shared_key' => 'synthetic-shared', 'services.exotic_crm_sync.shared_key_platform_ids' => (string) $platform->id]);
        $timeouts = [];
        Http::fake(function ($r, $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'];

            return Http::response(['ok' => true]);
        });
        $sync = new WpSyncService($platform);
        $bounded = $sync->withReadBudget(fn () => 0.25);
        $this->assertNotSame($sync, $bounded);
        $bounded->getClientProfile(321);
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('crm-user:secret')) && $r->hasHeader('X-Exotic-CRM-Sync-Key', 'synthetic-shared'));
        $this->assertSame([0.25], $timeouts);
        try {
            $sync->withReadBudget(fn () => 0.0)->getClientProfile(321);
        } catch (\RuntimeException) {
        }
        Http::assertSentCount(1);
    }

    public function test_snapshot_and_catalogue_share_the_run_deadline_without_an_extra_read(): void
    {
        $platform = Platform::factory()->create();
        $now = 0.0;
        $run = new BioGenerationRun(clock: function () use (&$now) {
            return $now;
        });
        $now = 29.0;
        $catalog = app(LinkCatalogService::class)->forPlatform($platform->id, $run);
        $this->assertNotEmpty($catalog);
        Http::assertNothingSent();
    }
}
