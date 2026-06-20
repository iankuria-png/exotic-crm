<?php

namespace Tests\Feature;

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
            ->assertJsonStructure(['cells', 'peak', 'window', 'avg_per_active_hour', 'total_normalized'])
            ->json();

        $this->assertCount(168, $peakHours['cells']);
        $this->assertCount(168, collect($peakHours['cells'])->map(fn (array $cell) => $cell['dow'] . '-' . $cell['hour'])->unique());

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
            'email' => Str::uuid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => false,
            'assigned_market_ids' => [],
        ], $overrides));
    }
}
