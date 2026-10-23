<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\ReportingFxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GambiaReportingCurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_gambia_cfa_does_not_make_all_market_revenue_partial_or_mutate_payments(): void
    {
        config(['services.reporting_fx.enabled' => true]);
        Http::fake();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_ceo' => true, 'status' => 'active']));
        $gambia = Platform::factory()->create([
            'name' => 'Gambia', 'country' => 'Gambia', 'currency_code' => 'GMD',
            'supported_currencies' => ['GMD', 'CFA'], 'multi_currency_wallet_enabled' => true,
        ]);
        $other = Platform::factory()->create(['name' => 'Other market', 'currency_code' => 'USD']);
        foreach (['XOF' => 0.0017, 'GMD' => 0.014] as $currency => $rate) {
            ReportingFxRate::query()->create([
                'provider' => 'currencyapi', 'source_currency' => $currency, 'target_currency' => 'USD',
                'rate_date' => '2026-09-28', 'rate' => $rate, 'fetched_at' => now(),
            ]);
        }
        $payments = collect([
            [$gambia, 'CFA', 10000], [$gambia, 'GMD', 2877], [$other, 'USD', 100],
        ])->map(fn ($row) => Payment::factory()->create([
            'platform_id' => $row[0]->id, 'currency' => $row[1], 'amount' => $row[2],
            'status' => 'completed', 'purpose' => 'subscription',
            'created_at' => '2026-09-28 10:00:00', 'completed_at' => '2026-09-28 10:00:00',
        ]));
        $before = $payments->map(fn ($p) => $p->fresh()->getAttributes())->all();
        $params = 'horizon=custom&from=2026-09-28&to=2026-09-28&reporting_currency=USD';

        $summary = $this->getJson('/api/crm/dashboard/ceo/summary?'.$params)->assertOk();
        $this->assertFalse(
            $summary->json('metrics.collected_revenue.value.normalization_meta.partial'),
            json_encode($summary->json('metrics.collected_revenue.value.normalization_meta'))
        );
        $summary
            ->assertJsonPath('metrics.collected_revenue.value.normalized_total', 157.28)
            ->assertJsonPath('metrics.collected_revenue.value.normalization_meta.partial', false)
            ->assertJsonPath('metrics.collected_revenue.value.normalization_meta.missing_currencies', [])
            ->assertJsonPath('metrics.collected_revenue.value.payments_count', 3);
        $this->getJson('/api/crm/dashboard/ceo/summary?'.$params.'&platform_id='.$gambia->id)->assertOk()
            ->assertJsonPath('metrics.collected_revenue.value.normalized_total', 57.28)
            ->assertJsonPath('metrics.collected_revenue.value.normalization_meta.partial', false);
        $this->getJson('/api/crm/reports/summary?from=2026-09-28&to=2026-09-28&currency_mode=flat&reporting_currency=USD')->assertOk()
            ->assertJsonPath('kpis.total_revenue_normalized', 157.28)
            ->assertJsonPath('kpis.total_revenue_normalization_meta.partial', false);
        $this->assertSame($before, $payments->map(fn ($p) => $p->fresh()->getAttributes())->all());
        Http::assertNothingSent();
    }
}
