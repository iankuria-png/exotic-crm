<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\ProjectIntelligenceService;
use App\Services\Seo\Llm\Adapters\DeepSeekAdapter;
use App\Services\Seo\Llm\LlmClient;
use App\Services\Seo\Llm\LlmResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiInsightsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.insights.enabled' => true,
            'ai.insights.read_connection' => config('database.default'),
            'ai.insights.default_row_limit' => 25,
            'ai.insights.max_row_limit' => 100,
            'ai.insights.rate_limit_per_minute' => 12,
            'ai.insights.daily_cost_cap_usd' => 5.0,
            'ai.insights.show_generated_sql' => true,
            'ai.project_intelligence.enabled' => true,
            'services.seo_engine.providers' => ['deepseek'],
            'ai.providers.force_provider' => null,
        ]);
    }

    public function test_sales_user_is_forbidden(): void
    {
        Sanctum::actingAs($this->user(['role' => 'sales']));

        $this->postJson('/api/crm/ai/ask', ['question' => 'How much revenue did we make?'])
            ->assertForbidden();
    }

    public function test_admin_can_ask_business_data_and_get_sql_rows_and_log_entries(): void
    {
        $this->bindContextAwareAi();
        [$platform, $product] = $this->market('Nairobi', 'Kenya');
        $this->payment($platform, $product, ['amount' => 250, 'completed_at' => Carbon::parse('2026-05-20')]);
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $response = $this->postJson('/api/crm/ai/ask', [
            'question' => 'Which markets had revenue?',
            'source' => 'business_data',
        ])->assertOk();

        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('source', 'business_data')
            ->assertJsonPath('reporting_currency', 'USD')
            ->assertJsonPath('row_count', 1)
            ->assertJsonPath('rows.0.platform_id', $platform->id)
            ->assertJsonPath('columns.0', 'platform_id')
            ->assertJsonPath('column_meta.revenue_usd.type', 'money')
            ->assertJsonPath('column_meta.revenue_usd.currency', 'USD');

        $this->assertStringContainsString('vw_market_revenue', $response->json('generated_sql'));
        $this->assertStringContainsString('Summary from rows', $response->json('answer'));
        $this->assertSame(2, AiInteraction::where('feature', 'like', 'insights%')->count());
        $this->assertNotNull(AiInteraction::where('feature', 'insights_sql')->first()?->generated_sql);
    }

    public function test_invalid_json_sql_payload_is_rejected(): void
    {
        $this->bindAiSequence(['SELECT * FROM vw_market_revenue LIMIT 10']);
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $this->postJson('/api/crm/ai/ask', [
            'question' => 'Show revenue',
            'source' => 'business_data',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'invalid_sql')
            ->assertJsonPath('reason', 'invalid_json');
    }

    public function test_base_table_sql_is_rejected_before_execution(): void
    {
        $this->bindSql('SELECT platform_id, amount FROM payments LIMIT 10');
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $this->postJson('/api/crm/ai/ask', [
            'question' => 'Show raw payments',
            'source' => 'business_data',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'invalid_sql')
            ->assertJsonPath('reason', 'table_not_allowed');
    }

    public function test_comments_stacked_queries_and_write_keywords_are_rejected(): void
    {
        Sanctum::actingAs($this->user(['role' => 'admin']));

        foreach ([
            ['SELECT platform_id FROM vw_market_revenue -- comment LIMIT 10', 'comment'],
            ['SELECT platform_id FROM vw_market_revenue LIMIT 10; SELECT * FROM vw_agent_perf LIMIT 10', 'multi_statement'],
            ['SELECT platform_id FROM vw_market_revenue WHERE drop IS NULL LIMIT 10', 'forbidden_keyword'],
        ] as [$sql, $reason]) {
            $this->bindSql($sql);

            $this->postJson('/api/crm/ai/ask', [
                'question' => 'Try this query ' . Str::random(6),
                'source' => 'business_data',
            ])->assertStatus(422)
                ->assertJsonPath('reason', $reason);
        }
    }

    public function test_missing_limit_is_bounded_by_validator(): void
    {
        $this->bindSql('SELECT platform_id, market_name, revenue_usd FROM vw_market_revenue');
        [$platform, $product] = $this->market();
        $this->payment($platform, $product, ['amount' => 80]);
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $response = $this->postJson('/api/crm/ai/ask', [
            'question' => 'Revenue by market',
            'source' => 'business_data',
        ])->assertOk();

        $this->assertStringEndsWith('LIMIT 25', $response->json('generated_sql'));
    }

    public function test_sub_admin_market_scope_is_enforced_server_side(): void
    {
        $this->bindContextAwareAi();
        [$allowed, $productA] = $this->market('Allowed', 'Kenya');
        [$blocked, $productB] = $this->market('Blocked', 'Uganda');
        $this->payment($allowed, $productA, ['amount' => 100]);
        $this->payment($blocked, $productB, ['amount' => 900]);

        Sanctum::actingAs($this->user([
            'role' => 'sub_admin',
            'assigned_market_ids' => [$allowed->id],
        ]));

        $response = $this->postJson('/api/crm/ai/ask', [
            'question' => 'Show all market revenue',
            'source' => 'business_data',
        ])->assertOk();

        $this->assertSame(1, $response->json('row_count'));
        $this->assertSame($allowed->id, $response->json('rows.0.platform_id'));
        $this->assertStringContainsString("platform_id IN ({$allowed->id})", $response->json('generated_sql'));
    }

    public function test_no_rows_returns_empty_state_answer_without_hallucinating(): void
    {
        $this->bindContextAwareAi();
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $response = $this->postJson('/api/crm/ai/ask', [
            'question' => 'Revenue by market',
            'source' => 'business_data',
        ])->assertOk();

        $response->assertJsonPath('row_count', 0);
        $this->assertStringContainsString('No matching records', $response->json('answer'));
    }

    public function test_source_disabled_returns_422_before_ai_call(): void
    {
        config(['ai.insights.sources.business_data' => false]);
        $this->bindContextAwareAi();
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $this->postJson('/api/crm/ai/ask', [
            'question' => 'Revenue by market',
            'source' => 'business_data',
        ])->assertStatus(422)
            ->assertJsonPath('status', 'source_disabled');

        $this->assertSame(0, AiInteraction::count());
    }

    public function test_read_only_connection_unavailable_returns_source_state(): void
    {
        config(['ai.insights.read_connection' => 'missing_readonly_connection']);
        $this->bindSql('SELECT platform_id, market_name, revenue_usd FROM vw_market_revenue LIMIT 10');
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $this->postJson('/api/crm/ai/ask', [
            'question' => 'Revenue by market',
            'source' => 'business_data',
        ])->assertStatus(503)
            ->assertJsonPath('status', 'source_unavailable');
    }

    public function test_provider_failure_returns_503_for_sql_generation(): void
    {
        $this->bindFailingAi();
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $this->postJson('/api/crm/ai/ask', [
            'question' => 'Revenue by market',
            'source' => 'business_data',
        ])->assertStatus(503)
            ->assertJsonPath('status', 'provider_unavailable');
    }

    public function test_daily_cost_cap_blocks_request(): void
    {
        config(['ai.insights.daily_cost_cap_usd' => 0.01]);
        AiInteraction::create([
            'feature' => 'insights_summary',
            'user_id' => null,
            'status' => 'success',
            'provider' => 'deepseek',
            'est_cost_usd' => 1,
        ]);
        $this->bindContextAwareAi();
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $this->postJson('/api/crm/ai/ask', [
            'question' => 'Revenue by market',
            'source' => 'business_data',
        ])->assertStatus(429)
            ->assertJsonPath('status', 'cost_capped');
    }

    public function test_mutation_requests_are_refused_and_logged_without_provider_call(): void
    {
        $this->bindFailingAi();
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $this->postJson('/api/crm/ai/ask', [
            'question' => 'Deploy the latest billing fix to production',
            'source' => 'project_status',
        ])->assertOk()
            ->assertJsonPath('status', 'refused')
            ->assertJsonPath('source', 'guardrail');

        $this->assertSame(1, AiInteraction::where('feature', 'insights_refused')->count());
    }

    public function test_project_status_answer_contains_commit_evidence(): void
    {
        $this->bindContextAwareAi();
        $this->bindProjectContext();
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $response = $this->postJson('/api/crm/ai/ask', [
            'question' => 'What shipped recently?',
            'source' => 'project_status',
        ])->assertOk();

        $response->assertJsonPath('source', 'project_status')
            ->assertJsonPath('project.commits.0.short_sha', 'abc1234');
        $this->assertStringContainsString('abc1234', $response->json('answer'));
    }

    public function test_hybrid_question_returns_data_and_project_evidence(): void
    {
        $this->bindContextAwareAi();
        $this->bindProjectContext();
        [$platform, $product] = $this->market();
        $this->payment($platform, $product, ['amount' => 42]);
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $response = $this->postJson('/api/crm/ai/ask', [
            'question' => 'Did recent billing commits affect revenue?',
            'source' => 'hybrid',
        ])->assertOk();

        $response->assertJsonPath('source', 'hybrid')
            ->assertJsonPath('row_count', 1)
            ->assertJsonPath('project.commits.0.short_sha', 'abc1234');
        $this->assertStringContainsString('Summary from rows', $response->json('answer'));
        $this->assertStringContainsString('abc1234', $response->json('answer'));
    }

    public function test_sales_data_source_returns_agent_performance_rows(): void
    {
        $this->bindContextAwareAi();
        [$platform, $product] = $this->market();
        $agent = $this->user(['role' => 'sales']);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'assigned_to' => $agent->id,
        ]);
        $this->payment($platform, $product, ['amount' => 150, 'deal_id' => $deal->id]);
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $response = $this->postJson('/api/crm/ai/ask', [
            'question' => 'Which agent performed best?',
            'source' => 'sales_data',
        ])->assertOk();

        $response->assertJsonPath('source', 'sales_data')
            ->assertJsonPath('rows.0.agent_id', $agent->id);
        $this->assertStringContainsString('vw_agent_perf', $response->json('generated_sql'));
    }

    public function test_generated_sql_visibility_setting_hides_sql(): void
    {
        config(['ai.insights.show_generated_sql' => false]);
        $this->bindContextAwareAi();
        [$platform, $product] = $this->market();
        $this->payment($platform, $product);
        Sanctum::actingAs($this->user(['role' => 'admin']));

        $this->postJson('/api/crm/ai/ask', [
            'question' => 'Revenue by market',
            'source' => 'business_data',
        ])->assertOk()
            ->assertJsonPath('generated_sql', null);
    }

    private function bindSql(string $sql): void
    {
        $this->bindAiSequence([
            json_encode(['sql' => $sql]),
            'Summary from rows.',
        ]);
    }

    private function bindContextAwareAi(): void
    {
        $this->app->bind(DeepSeekAdapter::class, fn () => new class implements LlmClient {
            public function name(): string { return 'deepseek'; }
            public function isAvailable(): bool { return true; }
            public function generate(string $system, string $user, array $opts = []): LlmResponse
            {
                if (str_contains($system, 'SINGLE read-only MySQL SELECT')) {
                    $sql = str_contains(mb_strtolower($user), 'agent')
                        ? 'SELECT platform_id, agent_id, agent_role, revenue_usd, payments_count FROM vw_agent_perf LIMIT 50'
                        : 'SELECT platform_id, market_name, revenue_usd, payments_count FROM vw_market_revenue LIMIT 50';

                    return new LlmResponse(text: json_encode(['sql' => $sql]), inputTokens: 100, outputTokens: 50);
                }

                if (str_contains($system, 'release/deployment analyst')) {
                    return new LlmResponse(text: 'Commit abc1234 explicitly mentions billing polish; anything else is inference from commit messages.', inputTokens: 80, outputTokens: 40);
                }

                return new LlmResponse(text: 'Summary from rows.', inputTokens: 80, outputTokens: 40);
            }
        });
    }

    private function bindAiSequence(array $responses): void
    {
        $this->app->bind(DeepSeekAdapter::class, fn () => new class($responses) implements LlmClient {
            private int $index = 0;

            public function __construct(private array $responses) {}
            public function name(): string { return 'deepseek'; }
            public function isAvailable(): bool { return true; }
            public function generate(string $system, string $user, array $opts = []): LlmResponse
            {
                $text = $this->responses[min($this->index, count($this->responses) - 1)];
                $this->index++;

                return new LlmResponse(text: $text, inputTokens: 100, outputTokens: 50);
            }
        });
    }

    private function bindFailingAi(): void
    {
        $this->app->bind(DeepSeekAdapter::class, fn () => new class implements LlmClient {
            public function name(): string { return 'deepseek'; }
            public function isAvailable(): bool { return true; }
            public function generate(string $system, string $user, array $opts = []): LlmResponse
            {
                throw new \RuntimeException('provider down');
            }
        });
    }

    private function bindProjectContext(array $overrides = []): void
    {
        $context = array_replace_recursive([
            'available' => true,
            'deployed_version' => ['short_sha' => 'deadbee', 'deployed_at' => '2026-05-29T10:00:00Z'],
            'tracked_branch' => 'main',
            'ahead_by' => 1,
            'remote' => ['available' => true, 'status' => 'ok', 'message' => null],
            'commits' => [[
                'sha' => 'abc123456789',
                'short_sha' => 'abc1234',
                'message' => 'Polish billing AI insight panel',
                'message_subject' => 'Polish billing AI insight panel',
                'author' => 'Dev',
                'authored_at' => '2026-05-30T09:00:00Z',
                'url' => 'https://github.test/repo/commit/abc1234',
            ]],
            'deployments' => [],
            'notes' => [],
        ], $overrides);

        $this->app->instance(ProjectIntelligenceService::class, new class($context) extends ProjectIntelligenceService {
            public function __construct(private array $context) {}
            public function enabled(): bool { return true; }
            public function context(): array { return $this->context; }
            public function evidenceText(array $context): string
            {
                $commit = $context['commits'][0] ?? [];

                return 'Commit ' . ($commit['short_sha'] ?? 'unknown') . ' | ' . ($commit['message_subject'] ?? 'unknown');
            }
        });
    }

    /** @return array{0: Platform, 1: Product} */
    private function market(string $name = 'Market', string $country = 'Kenya'): array
    {
        $platform = Platform::factory()->create([
            'name' => $name . ' ' . Str::random(5),
            'country' => $country,
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);

        return [$platform, $product];
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
            'completed_at' => now(),
        ], $overrides));
        $payment->save();

        return $payment;
    }

    private function user(array $overrides = []): User
    {
        $user = User::query()->create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => false,
            'assigned_market_ids' => [],
        ], $overrides));

        RateLimiter::clear('ai-insights:' . $user->id);

        return $user;
    }
}
