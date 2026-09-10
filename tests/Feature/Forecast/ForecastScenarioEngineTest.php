<?php

namespace Tests\Feature\Forecast;

use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Services\Forecast\ForecastBaselineService;
use App\Services\Forecast\ForecastClaimResolver;
use App\Services\Forecast\ForecastGoalSeekService;
use App\Services\Forecast\ForecastIdentityResolver;
use App\Services\PaymentRecoveryUnionFind;
use App\Services\Payments\PaymentIdentityTokenizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ForecastScenarioEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_union_find_is_psr4_resolvable_without_recovery_service_autoload_order(): void
    {
        $unionFind = new PaymentRecoveryUnionFind;

        $unionFind->union('phone:712345678', 'client:10');

        $this->assertSame($unionFind->find('phone:712345678'), $unionFind->find('client:10'));
    }

    public function test_tokenizer_preserves_legacy_tokens_and_bridges_crm_phone_format(): void
    {
        $tokenizer = app(PaymentIdentityTokenizer::class);
        $payment = new Payment(['phone' => '0712 345 678']);
        $payment->id = 55;
        $client = new Client(['phone_normalized' => '254712345678']);
        $client->id = 99;

        $this->assertSame(['phone:712345678'], $tokenizer->legacyTokens($payment));
        $this->assertSame(['payment:55'], $tokenizer->legacyTokens(tap(new Payment, fn (Payment $model) => $model->id = 55)));

        $resolver = app(ForecastIdentityResolver::class);
        $resolver->seedPayment($payment, '254');
        $resolver->seedClient($client, '254');

        $this->assertSame($resolver->rootForPayment($payment, '254'), $resolver->rootForClient($client, '254'));
        $this->assertGreaterThanOrEqual(1, $resolver->mergedCount());
    }

    public function test_claim_resolver_keeps_overlapping_roots_disjoint_by_precedence(): void
    {
        $result = app(ForecastClaimResolver::class)->resolve([
            'failed_recovery' => ['root-a', 'root-b'],
            'renewal' => ['root-a', 'root-c'],
            'churn_winback' => ['root-c', 'root-d'],
            'new_activations' => ['root-e'],
        ]);

        $this->assertSame(['root-a', 'root-b'], $result['sets']['failed_recovery']);
        $this->assertSame(['root-c'], $result['sets']['renewal']);
        $this->assertSame(['root-d'], $result['sets']['churn_winback']);
        $this->assertSame(1, $result['excluded']['renewal']);
        $this->assertSame(1, $result['excluded']['churn_winback']);
    }

    public function test_compute_endpoint_returns_bridge_rows_that_sum_to_scenario_total(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => true,
        ]));

        $baseline = $this->syntheticBaseline();
        $this->mock(ForecastBaselineService::class, function ($mock) use ($baseline) {
            $mock->shouldReceive('response')->once()->andReturn([
                'state' => 'ready',
                'baseline' => $baseline,
            ]);
        });

        $response = $this->postJson('/api/crm/dashboard/ceo/forecast/compute', [
            'from' => '2026-08-12',
            'to' => '2026-09-10',
            'currency' => 'USD',
            'mode' => 'replay',
            'levers' => [
                'failed_recovery' => ['target' => 65],
                'renewal' => ['target' => 55],
            ],
        ])->assertOk();

        $payload = $response->json();

        $this->assertSame(1000.0, (float) $payload['base_total']);
        $this->assertSame(1115.0, (float) $payload['scenario_total']);
        $this->assertSame(1115.0, (float) collect($payload['bridge_rows'])->sum('contribution'));
    }

    public function test_solver_is_deterministic_and_reports_shortfall_without_exceeding_ceilings(): void
    {
        $solver = app(ForecastGoalSeekService::class);
        $baseline = $this->syntheticBaseline();

        $first = $solver->solve($baseline, 2000, 3, ['new_market']);
        $second = $solver->solve($baseline, 2000, 3, ['new_market']);

        $this->assertSame($first, $second);
        $this->assertGreaterThan(0, collect($first['routes'])->firstWhere('band', 'conservative')['shortfall']);

        foreach ($first['routes'] as $route) {
            foreach ($route['moves'] as $move) {
                $this->assertGreaterThanOrEqual($move['from'], $move['to']);
            }
        }
    }

    private function syntheticBaseline(): array
    {
        return [
            'context' => [
                'from' => '2026-08-12',
                'to' => '2026-09-10',
                'days' => 30,
                'horizon_days' => 90,
            ],
            'baseline_revenue' => [
                'normalized_total' => 1000.0,
                'normalized_currency' => 'USD',
                'normalization_meta' => ['target_currency' => 'USD'],
            ],
            'projection' => [
                'monthly_run_rate' => 1000.0,
                'horizon_run_rate_total' => 3000.0,
            ],
            'levers' => [
                'failed_recovery' => [
                    'key' => 'failed_recovery',
                    'label' => 'Failed payment recovery',
                    'actual' => 50.0,
                    'suggested' => 60.0,
                    'eligible_units' => 100,
                    'unit_value' => 5.0,
                    'unit' => 'percentage_points',
                ],
                'renewal' => [
                    'key' => 'renewal',
                    'label' => 'Renewal rate',
                    'actual' => 50.0,
                    'suggested' => 60.0,
                    'eligible_units' => 80,
                    'unit_value' => 10.0,
                    'unit' => 'percentage_points',
                ],
                'new_activations' => [
                    'key' => 'new_activations',
                    'label' => 'New paid activations',
                    'actual' => 10.0,
                    'suggested' => 15.0,
                    'eligible_units' => 30,
                    'unit_value' => 20.0,
                    'unit' => 'count',
                ],
                'agent_targets' => [
                    'key' => 'agent_targets',
                    'label' => 'Agent capacity',
                    'actual' => 0.0,
                    'suggested' => 0.0,
                    'eligible_units' => 0,
                    'unit_value' => 0.0,
                    'unit' => 'capacity_check',
                ],
            ],
            'per_market' => [[
                'platform_id' => 1,
                'market_label' => 'Kenya',
                'levers' => [
                    'failed_recovery' => [
                        'key' => 'failed_recovery',
                        'label' => 'Failed payment recovery',
                        'actual' => 50.0,
                        'suggested' => 60.0,
                        'eligible_units' => 100,
                        'unit_value' => 5.0,
                        'unit' => 'percentage_points',
                    ],
                    'renewal' => [
                        'key' => 'renewal',
                        'label' => 'Renewal rate',
                        'actual' => 50.0,
                        'suggested' => 60.0,
                        'eligible_units' => 80,
                        'unit_value' => 10.0,
                        'unit' => 'percentage_points',
                    ],
                ],
            ]],
            'normalization_meta' => ['target_currency' => 'USD'],
            'config_digest' => 'sha256:test',
        ];
    }
}
