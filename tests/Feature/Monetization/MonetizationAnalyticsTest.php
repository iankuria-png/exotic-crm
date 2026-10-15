<?php

namespace Tests\Feature\Monetization;

use App\Models\ClientMonetizationPass;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\ReportingFxRate;
use App\Models\User;
use App\Models\VisitorContentPurchase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonetizationAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_trend_buckets_sales_checkout_and_pass_revenue_against_the_prior_window(): void
    {
        $kenya = $this->platform('Nairobi', 'Kenya', 'KES');
        Sanctum::actingAs($this->admin());
        $today = now()->startOfDay()->addHours(9);
        $yesterday = $today->copy()->subDay();

        $this->purchase($kenya, $today, 500, 'active', 'completed', 400);
        $this->purchase($kenya, $today, 300, 'active', 'completed', 240);
        $this->purchase($kenya, $today, 900, 'failed', 'failed');
        $this->purchase($kenya, $yesterday, 200, 'active', 'completed', 160);
        $this->purchase($kenya, $today, 9999, 'active', 'completed', 9999, true);
        $this->pass($kenya, $today, 450);
        // Prior window: the two days before the selected range.
        $this->purchase($kenya, $today->copy()->subDays(3), 100, 'active', 'completed', 80);

        $from = $yesterday->toDateString();
        $to = $today->toDateString();
        $response = $this->getJson("/api/crm/monetization/analytics?from={$from}&to={$to}&currency_mode=native&bucket=day")
            ->assertOk()
            ->json();

        $this->assertSame('day', $response['window']['bucket']);
        $this->assertSame('native', $response['window']['currency_mode']);
        $this->assertSame('KES', $response['window']['currency']);
        $this->assertSame(1000.0, (float) $response['totals']['gross']);
        $this->assertSame(800.0, (float) $response['totals']['creator_credit']);
        $this->assertSame(450.0, (float) $response['totals']['pass_revenue']);
        $this->assertSame(3, $response['totals']['purchases']);
        $this->assertSame(4, $response['totals']['attempts']);
        $this->assertSame(75.0, (float) $response['totals']['completion_rate']);
        $this->assertSame(100.0, (float) $response['prior_totals']['gross']);
        $this->assertSame(900.0, (float) $response['deltas']['gross']);

        $points = collect($response['points'])->keyBy('label');
        $this->assertSame(800.0, (float) $points[$to]['gross']);
        $this->assertSame(450.0, (float) $points[$to]['pass_revenue']);
        $this->assertSame(3, $points[$to]['attempts']);
        $this->assertSame(66.7, (float) $points[$to]['completion_rate']);
        $this->assertSame(200.0, (float) $points[$from]['gross']);
        $this->assertSame(100.0, (float) $points[$from]['prior_gross']);
    }

    public function test_flat_mode_converts_mixed_markets_and_names_currencies_without_a_rate(): void
    {
        $kenya = $this->platform('Nairobi', 'Kenya', 'KES');
        $ghana = $this->platform('Accra', 'Ghana', 'GHS');
        $nigeria = $this->platform('Lagos', 'Nigeria', 'NGN');
        Sanctum::actingAs($this->admin());
        $today = now()->startOfDay()->addHours(9);
        ReportingFxRate::create(['provider' => 'currencyapi', 'source_currency' => 'KES', 'target_currency' => 'USD', 'rate_date' => $today->toDateString(), 'rate' => 0.01, 'fetched_at' => now()]);
        ReportingFxRate::create(['provider' => 'currencyapi', 'source_currency' => 'GHS', 'target_currency' => 'USD', 'rate_date' => $today->toDateString(), 'rate' => 0.1, 'fetched_at' => now()]);

        $this->purchase($kenya, $today, 1000, 'active', 'completed', 800);
        $this->purchase($ghana, $today, 50, 'active', 'completed', 40);
        $this->purchase($nigeria, $today, 7000, 'active', 'completed', 5600);

        $day = $today->toDateString();
        $flat = $this->getJson("/api/crm/monetization/analytics?from={$day}&to={$day}&currency_mode=flat&reporting_currency=USD")
            ->assertOk()
            ->json();

        $this->assertSame('USD', $flat['window']['currency']);
        // 1000 KES -> 10 USD, 50 GHS -> 5 USD; NGN has no rate and is excluded by name.
        $this->assertSame(15.0, (float) $flat['totals']['gross']);
        $this->assertSame(['NGN'], $flat['totals']['unresolved_currencies']);
        $markets = collect($flat['markets'])->keyBy('name');
        $this->assertSame(10.0, (float) $markets['Nairobi']['gross']);
        $this->assertSame(['KES' => 1000], array_map('intval', $markets['Nairobi']['native_gross']));
        $this->assertTrue($markets['Lagos']['fx_unresolved']);
        $this->assertSame('Nairobi', $flat['markets'][0]['name']);

        $native = $this->getJson("/api/crm/monetization/analytics?from={$day}&to={$day}&currency_mode=native&reporting_currency=USD")
            ->assertOk()
            ->json();
        $this->assertTrue($native['window']['native_fallback']);
        $this->assertSame('flat', $native['window']['currency_mode']);

        $scoped = $this->getJson("/api/crm/monetization/analytics?from={$day}&to={$day}&currency_mode=native&platform_id={$ghana->id}")
            ->assertOk()
            ->json();
        $this->assertSame('GHS', $scoped['window']['currency']);
        $this->assertSame(50.0, (float) $scoped['totals']['gross']);
        $this->assertCount(1, $scoped['markets']);
    }

    public function test_all_time_starts_at_first_sale_and_skips_the_prior_window(): void
    {
        $kenya = $this->platform('Nairobi', 'Kenya', 'KES');
        Sanctum::actingAs($this->admin());
        $this->purchase($kenya, now()->subDays(10), 200, 'active', 'completed', 160);

        $response = $this->getJson('/api/crm/monetization/analytics?currency_mode=native')->assertOk()->json();

        $this->assertTrue($response['window']['all_time']);
        $this->assertSame(now()->subDays(10)->toDateString(), $response['window']['from']);
        $this->assertNull($response['prior_totals']);
        $this->assertCount(11, $response['points']);
    }

    public function test_sales_staff_cannot_read_an_unassigned_market(): void
    {
        $kenya = $this->platform('Nairobi', 'Kenya', 'KES');
        $ghana = $this->platform('Accra', 'Ghana', 'GHS');
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$kenya->id]]));

        $this->getJson("/api/crm/monetization/analytics?platform_id={$ghana->id}")->assertForbidden();
    }

    private function platform(string $name, string $country, string $currency): Platform
    {
        return Platform::factory()->create(['name' => $name, 'country' => $country, 'currency_code' => $currency, 'supported_currencies' => [$currency]]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    private function purchase(Platform $platform, $at, float $amount, string $status, string $paymentStatus, float $credit = 0, bool $sandbox = false): VisitorContentPurchase
    {
        $payment = Payment::create(['platform_id' => $platform->id, 'amount' => $amount, 'currency' => $platform->currency_code, 'purpose' => Payment::PURPOSE_PREMIUM_CONTENT_SALE, 'provider_key' => 'kopokopo', 'provider_environment' => 'production', 'source' => 'website_private_content', 'status' => $paymentStatus]);
        $purchase = VisitorContentPurchase::create(['public_id' => (string) Str::uuid(), 'platform_id' => $platform->id, 'payment_id' => $payment->id, 'status' => $status, 'offer_kind' => 'single', 'offer_version' => 1, 'currency' => $platform->currency_code, 'gross_amount' => $amount, 'creator_credit_amount' => $credit, 'entitlement_snapshot_json' => [], 'visitor_phone_hash' => str_repeat('d', 64), 'visitor_phone_masked' => '*******0111', 'first_device_hash' => str_repeat('b', 64), 'public_token_hash' => str_repeat('c', 64), 'idempotency_key_hash' => hash('sha256', (string) Str::uuid()), 'is_sandbox' => $sandbox]);
        $purchase->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $purchase;
    }

    private function pass(Platform $platform, $at, float $paid): void
    {
        $pass = ClientMonetizationPass::forceCreate(['platform_id' => $platform->id, 'status' => 'active', 'starts_at' => $at, 'expires_at' => $at->copy()->addMonth(), 'duration_key' => '1_month', 'duration_days' => 30, 'currency' => $platform->currency_code, 'list_amount' => $paid, 'subsidy_amount' => 0, 'paid_amount' => $paid, 'eligibility_snapshot_json' => [], 'is_sandbox' => false, 'idempotency_key_hash' => hash('sha256', (string) Str::uuid())]);
        $pass->forceFill(['created_at' => $at, 'updated_at' => $at])->save();
    }
}
