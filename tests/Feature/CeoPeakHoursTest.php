<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ReportingFxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CeoPeakHoursTest extends TestCase
{
    use RefreshDatabase;

    public function test_peak_hours_returns_dense_eat_grid_and_reconciles_to_collected_revenue(): void
    {
        config([
            'ceo.peak_hours_timezone' => 'Africa/Nairobi',
            'services.reporting_fx.enabled' => true,
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Nairobi',
            'country' => 'Kenya',
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);

        ReportingFxRate::query()->create([
            'provider' => 'manual',
            'source_currency' => 'KES',
            'target_currency' => 'USD',
            'rate_date' => '2026-06-10',
            'rate' => 0.01,
            'fetched_at' => now(),
        ]);

        $this->payment($platform, $product, [
            'amount' => 100,
            'currency' => 'USD',
            'completed_at' => '2026-06-10 22:30:00',
        ]);
        $this->payment($platform, $product, [
            'amount' => 150,
            'currency' => 'USD',
            'status' => 'expired',
            'completed_at' => '2026-06-10 22:45:00',
        ]);
        $this->payment($platform, $product, [
            'amount' => 200,
            'currency' => 'KES',
            'completed_at' => '2026-06-10 03:00:00',
        ]);
        $this->payment($platform, $product, [
            'amount' => 999,
            'currency' => 'USD',
            'purpose' => 'wallet_topup',
            'completed_at' => '2026-06-10 22:35:00',
        ]);

        Sanctum::actingAs($this->user(['role' => 'admin', 'is_ceo' => true]));

        $peakHours = $this->getJson('/api/crm/dashboard/ceo/peak-hours?horizon=custom&from=2026-06-10&to=2026-06-10&reporting_currency=USD')
            ->assertOk()
            ->assertJsonStructure(['cells', 'peak', 'window', 'avg_per_active_hour', 'total_normalized', 'weekdays'])
            ->json();

        $this->assertCount(168, $peakHours['cells']);
        $this->assertCount(7, $peakHours['weekdays']);
        $this->assertCount(168, collect($peakHours['cells'])->map(fn (array $cell) => $cell['dow'].'-'.$cell['hour'])->unique());

        $shiftedCell = collect($peakHours['cells'])->first(fn (array $cell) => $cell['dow'] === 3 && $cell['hour'] === 1);
        $this->assertNotNull($shiftedCell);
        $this->assertSame(2, (int) $shiftedCell['payments_count']);
        $this->assertEqualsWithDelta(250.0, (float) $shiftedCell['value'], 0.001);

        $this->assertSame(3, (int) data_get($peakHours, 'peak.dow'));
        $this->assertSame(1, (int) data_get($peakHours, 'peak.hour'));
        $this->assertSame(3, (int) $peakHours['total_payments']);
        $this->assertEqualsWithDelta(252.0, (float) $peakHours['total_normalized'], 0.001);
        $this->assertEqualsWithDelta(126.0, (float) $peakHours['avg_per_active_hour'], 0.001);

        $summary = $this->getJson('/api/crm/dashboard/ceo/summary?horizon=custom&from=2026-06-10&to=2026-06-10&reporting_currency=USD')
            ->assertOk()
            ->json();
        $this->assertEqualsWithDelta(
            (float) data_get($summary, 'metrics.collected_revenue.value.normalized_total'),
            (float) $peakHours['total_normalized'],
            0.001
        );
    }

    public function test_peak_hours_includes_weekday_click_summary_without_payment_level_payloads(): void
    {
        config([
            'ceo.peak_hours_timezone' => 'Africa/Nairobi',
            'services.reporting_fx.enabled' => true,
        ]);

        $kenya = Platform::factory()->create(['name' => 'Nairobi', 'country' => 'Kenya', 'currency_code' => 'USD']);
        $tanzania = Platform::factory()->create(['name' => 'Dar', 'country' => 'Tanzania', 'currency_code' => 'USD']);
        $kenyaProduct = Product::factory()->create(['platform_id' => $kenya->id, 'currency' => 'USD']);
        $tanzaniaProduct = Product::factory()->create(['platform_id' => $tanzania->id, 'currency' => 'USD']);
        $joanne = User::factory()->create(['name' => 'Joanne Yengo', 'role' => 'sales', 'status' => 'active']);
        $daniel = User::factory()->create(['name' => 'Daniel Kimani', 'role' => 'sales', 'status' => 'active']);
        $joanneDeal = Deal::factory()->create([
            'platform_id' => $tanzania->id,
            'product_id' => $tanzaniaProduct->id,
            'assigned_to' => $joanne->id,
        ]);
        $danielDeal = Deal::factory()->create([
            'platform_id' => $kenya->id,
            'product_id' => $kenyaProduct->id,
            'assigned_to' => $daniel->id,
        ]);

        $this->payment($tanzania, $tanzaniaProduct, [
            'amount' => 240,
            'deal_id' => $joanneDeal->id,
            'completed_at' => '2026-06-01 09:00:00',
        ]);
        $this->payment($kenya, $kenyaProduct, [
            'amount' => 60,
            'deal_id' => $joanneDeal->id,
            'completed_at' => '2026-06-08 10:00:00',
        ]);
        $this->payment($kenya, $kenyaProduct, [
            'amount' => 90,
            'deal_id' => $danielDeal->id,
            'completed_at' => '2026-06-08 11:00:00',
        ]);
        $this->payment($kenya, $kenyaProduct, [
            'amount' => 100,
            'completed_at' => '2026-05-18 12:00:00',
        ]);

        Sanctum::actingAs($this->user(['role' => 'admin', 'is_ceo' => true]));

        $payload = $this->getJson('/api/crm/dashboard/ceo/peak-hours?horizon=custom&from=2026-06-01&to=2026-06-15&reporting_currency=USD')
            ->assertOk()
            ->json();

        $monday = collect($payload['weekdays'])->firstWhere('label', 'Monday');

        $this->assertNotNull($monday);
        $this->assertSame(0, (int) $monday['dow']);
        $this->assertEqualsWithDelta(390.0, (float) $monday['value'], 0.001);
        $this->assertSame(3, (int) $monday['payments_count']);
        $this->assertEqualsWithDelta(130.0, (float) $monday['average_ticket'], 0.001);
        $this->assertSame(3, (int) $monday['occurrences']);
        $this->assertSame(2, (int) $monday['active_days']);
        $this->assertSame(1, (int) $monday['gap_count']);
        $this->assertSame(['2026-06-15'], $monday['gap_dates']);
        $this->assertEqualsWithDelta(100.0, (float) data_get($monday, 'baseline.value'), 0.001);
        $this->assertEqualsWithDelta(290.0, (float) $monday['delta_percent'], 0.001);
        $this->assertSame('increasing', $monday['trend_direction']);
        $this->assertSame('Joanne Yengo', data_get($monday, 'top_agent.name'));
        $this->assertEqualsWithDelta(300.0, (float) data_get($monday, 'top_agent.value'), 0.001);
        $this->assertSame('Tanzania', data_get($monday, 'top_country.country'));
        $this->assertEqualsWithDelta(240.0, (float) data_get($monday, 'top_country.value'), 0.001);
        $this->assertArrayNotHasKey('payments', $monday);
    }

    public function test_peak_hours_reconciles_when_market_uses_ambiguous_cfa_currency(): void
    {
        config([
            'ceo.peak_hours_timezone' => 'Africa/Nairobi',
            'services.reporting_fx.enabled' => true,
        ]);

        $kenya = Platform::factory()->create(['name' => 'Nairobi', 'country' => 'Kenya', 'currency_code' => 'USD']);
        $kenyaProduct = Product::factory()->create(['platform_id' => $kenya->id, 'currency' => 'USD']);
        $senegal = Platform::factory()->create(['name' => 'Dakar', 'country' => 'Senegal', 'currency_code' => 'XOF']);
        $senegalProduct = Product::factory()->create(['platform_id' => $senegal->id, 'currency' => 'XOF']);

        ReportingFxRate::query()->create([
            'provider' => 'manual',
            'source_currency' => 'XOF',
            'target_currency' => 'USD',
            'rate_date' => '2026-06-10',
            'rate' => 0.002,
            'fetched_at' => now(),
        ]);

        $this->payment($kenya, $kenyaProduct, ['amount' => 100, 'currency' => 'USD', 'completed_at' => '2026-06-10 07:00:00']);
        // 'CFA' is ambiguous — resolvable to XOF only via the Senegal platform country context.
        $this->payment($senegal, $senegalProduct, ['amount' => 1000, 'currency' => 'CFA', 'completed_at' => '2026-06-10 09:00:00']);

        Sanctum::actingAs($this->user(['role' => 'admin', 'is_ceo' => true]));

        $peak = $this->getJson('/api/crm/dashboard/ceo/peak-hours?horizon=custom&from=2026-06-10&to=2026-06-10&reporting_currency=USD')
            ->assertOk()->json();
        $summary = $this->getJson('/api/crm/dashboard/ceo/summary?horizon=custom&from=2026-06-10&to=2026-06-10&reporting_currency=USD')
            ->assertOk()->json();

        $collected = (float) data_get($summary, 'metrics.collected_revenue.value.normalized_total');
        $this->assertEqualsWithDelta(102.0, $collected, 0.001); // 100 USD + 1000 CFA * 0.002
        $this->assertEqualsWithDelta($collected, (float) $peak['total_normalized'], 0.001);

        // The CFA cell must carry its converted revenue, not collapse to zero.
        $cfaCell = collect($peak['cells'])->first(fn (array $cell) => abs((float) $cell['value'] - 2.0) < 0.001);
        $this->assertNotNull($cfaCell, 'CFA cell should carry non-zero normalized revenue');
        $this->assertSame(1, (int) $cfaCell['payments_count']);
    }

    public function test_peak_hours_route_requires_ceo_access(): void
    {
        Sanctum::actingAs($this->user(['role' => 'admin', 'is_ceo' => false]));

        $this->getJson('/api/crm/dashboard/ceo/peak-hours')
            ->assertForbidden();
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

    private function user(array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => false,
            'assigned_market_ids' => [],
        ], $overrides));
    }
}
