<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\Deal;
use App\Models\ReportingFxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_dashboard_stays_available_when_fx_provider_rejects_a_currency(): void
    {
        config([
            'services.reporting_fx.enabled' => true,
            'services.reporting_fx.api_key' => 'test-key',
        ]);
        Http::fake([
            '*' => Http::response(['message' => 'Validation Error'], 422),
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => Str::random(8) . '@example.test',
        ]);
        Sanctum::actingAs($admin);

        $platform = Platform::factory()->create([
            'name' => 'Ghana',
            'country' => 'Ghana',
            'currency_code' => 'GHS',
        ]);
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'amount' => 2360,
            'currency' => 'GHS',
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => '2026-04-23 09:00:00',
            'completed_at' => '2026-04-23 09:10:00',
        ]);

        $dashboard = $this->getJson('/api/crm/dashboard?from=2026-04-23&to=2026-04-23&currency_mode=flat&reporting_currency=USD');

        $dashboard->assertOk()
            ->assertJsonPath('filters.currency_mode', 'flat')
            ->assertJsonPath('kpis.revenue_window', 2360)
            ->assertJsonPath('kpis.revenue_window_normalized', null)
            ->assertJsonPath('kpis.revenue_window_normalization_meta.partial', true)
            ->assertJsonPath('kpis.revenue_window_normalization_meta.missing_currencies.0', 'GHS');
        $this->assertSame(2360.0, (float) $dashboard->json('kpis.revenue_window_breakdown.GHS'));
        Http::assertNothingSent();
    }

    public function test_dashboard_native_all_markets_does_not_call_fx_provider(): void
    {
        config([
            'services.reporting_fx.enabled' => true,
            'services.reporting_fx.api_key' => 'test-key',
        ]);
        Http::fake([
            '*' => Http::response(['message' => 'Unexpected FX call'], 500),
        ]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => Str::random(8) . '@example.test',
        ]);
        Sanctum::actingAs($admin);

        $kenya = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'KES',
        ]);
        $ghana = Platform::factory()->create([
            'name' => 'Ghana',
            'country' => 'Ghana',
            'currency_code' => 'GHS',
        ]);

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

        $dashboard = $this->getJson('/api/crm/dashboard?from=2026-04-20&to=2026-04-20&currency_mode=native&reporting_currency=USD');

        $dashboard->assertOk()
            ->assertJsonPath('filters.currency_mode', 'native')
            ->assertJsonPath('kpis.revenue_window', null)
            ->assertJsonPath('kpis.revenue_window_normalized', null)
            ->assertJsonPath('kpis.revenue_window_normalization_meta.as_of', null);
        $this->assertSame(1000.0, (float) $dashboard->json('kpis.revenue_window_breakdown.KES'));
        $this->assertSame(200.0, (float) $dashboard->json('kpis.revenue_window_breakdown.GHS'));
        $this->assertSame(1000.0, (float) $dashboard->json('country_revenue.0.current_revenue_breakdown.KES'));
        $this->assertSame(200.0, (float) $dashboard->json('country_revenue.1.current_revenue_breakdown.GHS'));

        Http::assertNothingSent();
    }

    public function test_reports_package_and_owner_rollups_use_platform_context_for_cfa(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => Str::random(8) . '@example.test',
        ]);
        Sanctum::actingAs($admin);

        $platform = Platform::factory()->create([
            'name' => 'Benin',
            'country' => 'Benin Republic',
            'currency_code' => 'CFA',
        ]);
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'VIP',
            'display_name' => 'VIP',
            'currency' => 'CFA',
        ]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'assigned_to' => $admin->id,
            'amount' => 1000,
            'currency' => 'CFA',
        ]);

        $this->rate('XOF', '2026-04-23', 0.00165);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'deal_id' => $deal->id,
            'amount' => 1000,
            'currency' => 'CFA',
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => '2026-04-23 09:00:00',
            'completed_at' => '2026-04-23 09:10:00',
        ]);

        $reports = $this->getJson('/api/crm/reports/summary?from=2026-04-23&to=2026-04-23&currency_mode=flat&reporting_currency=USD');

        $reports->assertOk()
            ->assertJsonPath('package_revenue.0.normalized_total', 1.65)
            ->assertJsonPath('package_revenue.0.normalization_meta.partial', false)
            ->assertJsonPath('package_revenue.0.normalization_meta.currency_aliases.0.source_currency', 'CFA')
            ->assertJsonPath('package_revenue.0.normalization_meta.currency_aliases.0.canonical_currency', 'XOF')
            ->assertJsonPath('owner_performance.0.normalized_revenue', 1.65)
            ->assertJsonPath('owner_performance.0.normalization_meta.partial', false);
        $this->assertSame(1000.0, (float) $reports->json('package_revenue.0.revenue_breakdown.CFA'));
        $this->assertSame(1000.0, (float) $reports->json('owner_performance.0.revenue_breakdown.CFA'));
    }

    public function test_dashboard_all_markets_country_revenue_includes_accessible_inactive_market_rows(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => Str::random(8) . '@example.test',
        ]);
        Sanctum::actingAs($admin);

        $inactiveKenya = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'KES',
            'is_active' => false,
        ]);

        $this->rate('KES', '2026-04-20', 0.0077);

        Payment::factory()->create([
            'platform_id' => $inactiveKenya->id,
            'amount' => 1000,
            'currency' => 'KES',
            'status' => 'completed',
            'purpose' => 'subscription',
            'created_at' => '2026-04-20 09:00:00',
            'completed_at' => '2026-04-20 09:10:00',
        ]);

        $dashboard = $this->getJson('/api/crm/dashboard?from=2026-04-20&to=2026-04-20&currency_mode=flat&reporting_currency=USD');

        $dashboard->assertOk()
            ->assertJsonPath('country_revenue.0.name', 'Kenya')
            ->assertJsonPath('country_revenue.0.current_revenue', 1000)
            ->assertJsonPath('country_revenue.0.current_revenue_normalized', 7.7)
            ->assertJsonPath('country_revenue.0.current_revenue_normalization_meta.partial', false);
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
