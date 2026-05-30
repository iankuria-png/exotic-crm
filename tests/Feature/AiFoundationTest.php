<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\ClientActiveSnapshot;
use App\Models\Deal;
use App\Models\IntegrationSetting;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiBriefingSettingsService;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiInsightsSettingsService;
use App\Services\Ai\MetricsSnapshotService;
use App\Services\Seo\Exceptions\AllProvidersFailedException;
use App\Services\Seo\Llm\Adapters\DeepSeekAdapter;
use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.providers.force_provider' => null]);
        config(['services.seo_engine.providers' => ['deepseek']]);
    }

    public function test_reporting_views_exist_and_expose_platform_scoped_usd_revenue(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);

        $this->payment($platform, $product, ['amount' => 100, 'completed_at' => now()]);
        $this->payment($platform, $product, ['amount' => 50, 'completed_at' => now()]);

        $rows = DB::table('vw_market_revenue')->where('platform_id', $platform->id)->get();

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(150.0, (float) $rows->first()->revenue_usd, 0.001);
        $this->assertSame(2, (int) $rows->first()->payments_count);

        // Zero-PII guarantee: payment view exposes no client identity columns.
        $columns = (array) DB::table('vw_payments_usd')->first();
        foreach (['client_id', 'name', 'phone', 'email', 'phone_normalized'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $columns);
        }
        $this->assertArrayHasKey('platform_id', $columns);
        $this->assertArrayHasKey('amount_usd', $columns);
    }

    public function test_ai_gateway_logs_one_success_row_with_cost_and_provider(): void
    {
        $this->bindFakeProvider(fn () => new LlmResponse(text: 'hello world', inputTokens: 1000, outputTokens: 2000));

        $gateway = app(AiGateway::class);
        $result = $gateway->generate('insights_chat', 'system prompt', 'user prompt', ['user_id' => null]);

        $this->assertSame('hello world', $result->text());
        $this->assertSame(1, AiInteraction::count());

        $row = AiInteraction::first();
        $this->assertSame('insights_chat', $row->feature);
        $this->assertSame('success', $row->status);
        $this->assertSame('deepseek', $row->provider);
        $this->assertSame(1000, $row->input_tokens);
        $this->assertSame(2000, $row->output_tokens);
        // deepseek rates: 0.27 in + 1.10 out per 1M tokens => 0.00027 + 0.0022 = 0.00247
        $this->assertEqualsWithDelta(0.00247, (float) $row->est_cost_usd, 0.0000001);
        $this->assertNotNull($row->prompt_hash);
        $this->assertIsArray($row->provider_attempts);
        $this->assertSame('success', $row->provider_attempts[0]['status']);
    }

    public function test_ai_gateway_logs_failure_row_and_rethrows(): void
    {
        $this->bindFakeProvider(function (): LlmResponse {
            throw new \RuntimeException('boom from provider');
        });

        $gateway = app(AiGateway::class);

        try {
            $gateway->generate('briefing_ceo', 'sys', 'usr');
            $this->fail('Expected AllProvidersFailedException');
        } catch (AllProvidersFailedException $e) {
            // expected
        }

        $this->assertSame(1, AiInteraction::count());
        $row = AiInteraction::first();
        $this->assertSame('failed', $row->status);
        $this->assertNull($row->provider);
        $this->assertSame(0, $row->input_tokens);
        $this->assertNotNull($row->error_message);
        $this->assertSame('failed', $row->provider_attempts[0]['status']);
    }

    public function test_gateway_respects_hash_only_prompt_logging(): void
    {
        config(['ai.providers.prompt_logging' => 'hash_only']);
        $this->bindFakeProvider(fn () => new LlmResponse(text: 'ok', inputTokens: 1, outputTokens: 1));

        app(AiGateway::class)->generate('insights_chat', 'secret system', 'secret user');

        $row = AiInteraction::first();
        $this->assertNull($row->prompt);
        $this->assertNotNull($row->prompt_hash);
    }

    public function test_metrics_snapshot_matches_payment_revenue_for_scope(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $other = Platform::factory()->create(['currency_code' => 'USD']);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);

        $this->payment($platform, $product, ['amount' => 200, 'completed_at' => now()]);
        $this->payment($other, $product, ['amount' => 999, 'platform_id' => $other->id, 'completed_at' => now()]);

        ClientActiveSnapshot::query()->create([
            'date' => now()->toDateString(),
            'platform_id' => $platform->id,
            'count' => 42,
        ]);

        $snapshot = app(MetricsSnapshotService::class)->forScope(
            [$platform->id],
            Carbon::now()->subDays(6),
            Carbon::now(),
        );

        $this->assertSame([$platform->id], $snapshot['scope']['platform_ids']);
        $this->assertFalse($snapshot['scope']['org_wide']);
        $this->assertEqualsWithDelta(200.0, (float) $snapshot['revenue']['normalized_total'], 0.001);
        $this->assertSame(1, $snapshot['revenue']['payments_count']);
        $this->assertSame(42, $snapshot['active_subscribers']['count']);
        $this->assertNotEmpty($snapshot['top_markets']);
        $this->assertSame($platform->id, $snapshot['top_markets'][0]['platform_id']);
    }

    public function test_metrics_snapshot_org_wide_includes_all_markets(): void
    {
        $a = Platform::factory()->create(['currency_code' => 'USD']);
        $b = Platform::factory()->create(['currency_code' => 'USD']);
        $product = Product::factory()->create(['platform_id' => $a->id, 'currency' => 'USD']);

        $this->payment($a, $product, ['amount' => 100, 'completed_at' => now()]);
        $this->payment($b, $product, ['amount' => 100, 'platform_id' => $b->id, 'completed_at' => now()]);

        $snapshot = app(MetricsSnapshotService::class)->forScope(null, Carbon::now()->subDays(6), Carbon::now());

        $this->assertTrue($snapshot['scope']['org_wide']);
        $this->assertEqualsWithDelta(200.0, (float) $snapshot['revenue']['normalized_total'], 0.001);
    }

    public function test_briefing_settings_defaults_and_override(): void
    {
        $service = app(AiBriefingSettingsService::class);

        $this->assertSame(5.0, $service->weeklyCostCapUsd());
        $this->assertSame(14, $service->linkTtlDays());

        $service->save(['weekly_cost_cap_usd' => 12.5, 'link_ttl_days' => 30, 'enabled' => true], null);

        $fresh = app(AiBriefingSettingsService::class);
        $this->assertSame(12.5, $fresh->weeklyCostCapUsd());
        $this->assertSame(30, $fresh->linkTtlDays());
        $this->assertTrue($fresh->enabled());
        $this->assertSame('ai_briefings_config', AiBriefingSettingsService::KEY);
        $this->assertNotNull(IntegrationSetting::where('key', 'ai_briefings_config')->first());
    }

    public function test_insights_settings_defaults_and_override(): void
    {
        $service = app(AiInsightsSettingsService::class);

        $this->assertSame(100, $service->defaultRowLimit());
        $this->assertSame(1000, $service->maxRowLimit());
        $this->assertTrue($service->sourceEnabled('business_data'));

        $service->save([
            'default_row_limit' => 250,
            'sources' => ['business_data' => false],
            'project_intelligence' => [
                'enabled' => false,
                'commit_lookback' => 12,
                'include_deployment_history' => false,
                'show_commit_urls' => false,
            ],
        ], null);

        $fresh = app(AiInsightsSettingsService::class);
        $this->assertSame(250, $fresh->defaultRowLimit());
        $this->assertFalse($fresh->sourceEnabled('business_data'));
        $this->assertFalse($fresh->projectIntelligenceEnabled());
        $this->assertSame(12, $fresh->projectCommitLookback());
        $this->assertFalse($fresh->includeDeploymentHistory());
        $this->assertFalse($fresh->showCommitUrls());
    }

    private function bindFakeProvider(\Closure $generate): void
    {
        $this->app->bind(DeepSeekAdapter::class, fn () => new class($generate) implements LlmClient {
            public function __construct(private \Closure $generate) {}

            public function name(): string
            {
                return 'deepseek';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function generate(string $system, string $user, array $opts = []): LlmResponse
            {
                return ($this->generate)($system, $user, $opts);
            }
        });
    }

    private function payment(Platform $platform, Product $product, array $overrides = []): Payment
    {
        $payment = Payment::factory()->make(array_merge([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'currency' => 'USD',
            'purpose' => 'subscription',
            'provider_environment' => null,
            'record_classification' => Payment::RECORD_CLASSIFICATION_LIVE,
            'reconciliation_state' => 'open',
            'resolution_code' => null,
            'source' => 'gateway',
            'status' => 'completed',
        ], $overrides));

        $payment->save();

        return $payment;
    }
}
