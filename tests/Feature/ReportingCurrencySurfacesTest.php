<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\ReportingFxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportingCurrencySurfacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_reports_and_payments_expose_usd_normalized_totals_additively(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => Str::random(8) . '@example.test',
        ]);
        Sanctum::actingAs($admin);

        $kenya = Platform::factory()->create(['name' => 'Kenya', 'country' => 'Kenya', 'currency_code' => 'KES']);
        $ghana = Platform::factory()->create(['name' => 'Ghana', 'country' => 'Ghana', 'currency_code' => 'GHS']);

        $this->rate('KES', '2026-04-20', 0.0077);
        $this->rate('GHS', '2026-04-20', 0.085);

        Payment::factory()->create([
            'platform_id' => $kenya->id,
            'amount' => 1000,
            'currency' => 'KES',
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => '2026-04-20 09:00:00',
            'completed_at' => '2026-04-20 09:10:00',
        ]);
        Payment::factory()->create([
            'platform_id' => $ghana->id,
            'amount' => 200,
            'currency' => 'GHS',
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => '2026-04-20 10:00:00',
            'completed_at' => '2026-04-20 10:10:00',
        ]);

        $dashboard = $this->getJson('/api/crm/dashboard?from=2026-04-20&to=2026-04-20&currency_mode=flat&reporting_currency=USD');
        $dashboard->assertOk()
            ->assertJsonPath('filters.currency_mode', 'flat')
            ->assertJsonPath('kpis.normalized_currency', 'USD')
            ->assertJsonPath('kpis.revenue_window', null)
            ->assertJsonPath('kpis.revenue_window_normalized', 24.7)
            ->assertJsonPath('kpis.revenue_window_normalization_meta.partial', false);
        $this->assertSame(1000.0, (float) $dashboard->json('kpis.revenue_window_breakdown.KES'));
        $this->assertSame(200.0, (float) $dashboard->json('kpis.revenue_window_breakdown.GHS'));

        $reports = $this->getJson('/api/crm/reports/summary?from=2026-04-20&to=2026-04-20&currency_mode=flat&reporting_currency=USD');
        $reports->assertOk()
            ->assertJsonPath('filters.currency_mode', 'flat')
            ->assertJsonPath('kpis.normalized_currency', 'USD')
            ->assertJsonPath('kpis.total_revenue', null)
            ->assertJsonPath('kpis.total_revenue_normalized', 24.7)
            ->assertJsonPath('kpis.total_revenue_normalization_meta.partial', false);

        $payments = $this->getJson('/api/crm/payments?from=2026-04-20&to=2026-04-20&currency_mode=flat&reporting_currency=USD');
        $payments->assertOk()
            ->assertJsonPath('stats.currency_mode', 'flat')
            ->assertJsonPath('stats.normalized_currency', 'USD')
            ->assertJsonPath('stats.confirmed_amount', null)
            ->assertJsonPath('stats.confirmed_normalized_amount', 24.7)
            ->assertJsonPath('stats.confirmed_normalization_meta.partial', false);
        $this->assertSame(1000.0, (float) $payments->json('stats.confirmed_amount_breakdown.KES'));
        $this->assertSame(200.0, (float) $payments->json('stats.confirmed_amount_breakdown.GHS'));
    }

    private function rate(string $sourceCurrency, string $rateDate, float $rate): void
    {
        ReportingFxRate::query()->create([
            'provider' => 'currencyapi',
            'source_currency' => $sourceCurrency,
            'target_currency' => 'USD',
            'rate_date' => $rateDate,
            'rate' => $rate,
            'fetched_at' => now(),
        ]);
    }
}
