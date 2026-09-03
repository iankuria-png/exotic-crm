<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression cover for the Gambia incident: a market configured with an ambiguous
 * "CFA" code could not be converted, and the market pie substituted its raw native
 * amount for the converted one — counting CFA 10,000 as USD 10,000 and handing that
 * market 89.7% of company revenue.
 */
class CeoMarketPieFxTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconvertible_market_is_excluded_from_the_total_instead_of_being_counted_as_target_currency(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $convertible = Platform::factory()->create([
            'name' => 'Tanzania',
            'country' => 'Tanzania',
            'currency_code' => 'USD',
        ]);

        // Gambia is in neither franc zone, so "CFA" cannot be canonicalised for it.
        $unconvertible = Platform::factory()->create([
            'name' => 'Gambia',
            'country' => 'Gambia',
            'currency_code' => 'CFA',
        ]);

        $this->payment($convertible, 450.00, 'USD');
        $this->payment($unconvertible, 10000.00, 'CFA');

        Sanctum::actingAs($this->ceo());

        $response = $this->getJson('/api/crm/dashboard/ceo/market-pie?horizon=custom&from=2026-05-01&to=2026-05-31')
            ->assertOk();

        // The headline total must be the converted revenue alone. Before the fix this
        // came back as 10450.0 — the native 10,000 folded in as though it were USD.
        $this->assertSame(450.0, (float) $response->json('total'));

        $markets = collect($response->json('markets'))->keyBy('name');

        $this->assertSame(100.0, (float) $markets['Tanzania']['share_percent']);
        $this->assertFalse($markets['Tanzania']['fx_unresolved']);

        // The unconvertible market stays visible and honest: no share, flagged, and
        // its native amount still reported so the misconfiguration is discoverable.
        $this->assertTrue($markets['Gambia']['fx_unresolved']);
        $this->assertSame(0.0, (float) $markets['Gambia']['share_percent']);
        $this->assertNull($markets['Gambia']['normalized_total']);
        $this->assertSame(
            ['CFA' => 10000.0],
            array_map('floatval', $markets['Gambia']['source_breakdown'])
        );
    }

    public function test_a_single_unconvertible_market_does_not_suppress_every_other_market_share(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $a = Platform::factory()->create(['name' => 'Tanzania', 'country' => 'Tanzania', 'currency_code' => 'USD']);
        $b = Platform::factory()->create(['name' => 'Zimbabwe', 'country' => 'Zimbabwe', 'currency_code' => 'USD']);
        $bad = Platform::factory()->create(['name' => 'Gambia', 'country' => 'Gambia', 'currency_code' => 'CFA']);

        $this->payment($a, 300.00, 'USD');
        $this->payment($b, 100.00, 'USD');
        $this->payment($bad, 999999.00, 'CFA');

        Sanctum::actingAs($this->ceo());

        $response = $this->getJson('/api/crm/dashboard/ceo/market-pie?horizon=custom&from=2026-05-01&to=2026-05-31')
            ->assertOk();

        $markets = collect($response->json('markets'))->keyBy('name');

        $this->assertSame(400.0, (float) $response->json('total'));
        $this->assertSame(75.0, (float) $markets['Tanzania']['share_percent']);
        $this->assertSame(25.0, (float) $markets['Zimbabwe']['share_percent']);
        $this->assertSame(0.0, (float) $markets['Gambia']['share_percent']);
    }

    public function test_single_currency_window_still_reports_natively_when_no_fx_rate_exists(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        // One currency across every market and no rate to convert with: the native sums
        // are directly comparable, so the pie must still rank and total them rather than
        // collapsing to zero. This is the single-market / FX-off deployment.
        $a = Platform::factory()->create(['name' => 'Nairobi', 'country' => 'Kenya', 'currency_code' => 'KES']);
        $b = Platform::factory()->create(['name' => 'Mombasa', 'country' => 'Kenya', 'currency_code' => 'KES']);

        $this->payment($a, 750.00, 'KES');
        $this->payment($b, 250.00, 'KES');

        Sanctum::actingAs($this->ceo());

        $response = $this->getJson('/api/crm/dashboard/ceo/market-pie?horizon=custom&from=2026-05-01&to=2026-05-31')
            ->assertOk();

        $markets = collect($response->json('markets'))->keyBy('name');

        $this->assertSame(1000.0, (float) $response->json('total'));
        $this->assertSame(75.0, (float) $markets['Nairobi']['share_percent']);
        $this->assertSame(25.0, (float) $markets['Mombasa']['share_percent']);
        $this->assertFalse($markets['Nairobi']['fx_unresolved']);
    }

    private function ceo(): User
    {
        return User::query()->create([
            'name' => 'CEO',
            'email' => Str::uuid().'@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'is_ceo' => true,
            'assigned_market_ids' => [],
        ]);
    }

    private function payment(Platform $platform, float $amount, string $currency): Payment
    {
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'currency' => $currency,
        ]);

        $payment = Payment::factory()->make([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'completed',
            'purpose' => 'subscription',
            'provider_environment' => null,
            'record_classification' => Payment::RECORD_CLASSIFICATION_LIVE,
            'reconciliation_state' => 'open',
            'resolution_code' => null,
            'source' => 'gateway',
            'completed_at' => '2026-05-15 10:00:00',
            'created_at' => '2026-05-15 09:00:00',
        ]);

        $payment->save();

        return $payment;
    }
}
