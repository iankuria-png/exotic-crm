<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\NativeScorecardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NativeScorecardTest extends TestCase
{
    use RefreshDatabase;

    private function period(): array
    {
        return ['from' => '2026-10-26', 'to' => '2026-11-01', 'prior_from' => '2026-10-19', 'prior_to' => '2026-10-25'];
    }

    private function payment(Platform $platform, string $at, array $attributes = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'platform_id' => $platform->id, 'currency' => $platform->currency_code,
            'product_id' => Product::query()->value('id') ?? Product::factory()->create()->id,
            'amount' => 700, 'status' => 'completed', 'purpose' => 'subscription',
            'completed_at' => $at, 'created_at' => $at,
        ], $attributes));
    }

    public function test_native_revenue_preserves_scope_currency_comparisons_and_staff_attribution(): void
    {
        $kenya = Platform::factory()->create(['name' => 'Kenya', 'currency_code' => 'KES']);
        $ghana = Platform::factory()->create(['name' => 'Ghana', 'currency_code' => 'GHS']);
        $staff = User::factory()->create();
        $deal = Deal::factory()->create(['platform_id' => $kenya->id, 'assigned_to' => $staff->id]);
        $this->payment($kenya, '2026-10-26 00:00:00', ['deal_id' => $deal->id]);
        $this->payment($kenya, '2026-10-19 00:00:00', ['amount' => 350, 'deal_id' => $deal->id]);
        $this->payment($kenya, '2026-11-01 23:59:59', ['amount' => 700, 'currency' => 'USD']);
        $this->payment($ghana, '2026-10-28 12:00:00', ['amount' => 99999]);
        $this->payment($kenya, '2026-10-28 12:00:00', ['amount' => 99999, 'purpose' => 'wallet_topup']);
        $this->payment($kenya, '2026-10-28 12:00:00', ['amount' => 99999, 'status' => 'failed']);
        $this->payment($kenya, '2026-10-28 12:00:00', ['amount' => 99999, 'record_classification' => Payment::RECORD_CLASSIFICATION_TEST]);
        $this->payment($kenya, '2026-10-18 23:59:59', ['amount' => 99999]);
        $this->payment($kenya, '2026-11-02 00:00:00', ['amount' => 99999]);
        $result = app(NativeScorecardService::class)->build([$kenya->id], $this->period());
        $currencies = collect($result['currencies'])->keyBy('key');
        $this->assertCount(2, $currencies);
        $this->assertSame(700.0, $currencies['KES']['current']);
        $this->assertSame(350.0, $currencies['KES']['prior']);
        $this->assertSame(100.0, $currencies['KES']['delta_percent']);
        $this->assertSame(100.0, $currencies['KES']['daily']);
        $this->assertSame(50.0, $currencies['KES']['prior_daily']);
        $this->assertSame(700.0, $currencies['USD']['current']);
        $this->assertNull($currencies['USD']['delta_percent']);
        $this->assertCount(1, $result['markets']);
        $this->assertSame($staff->id, $result['members'][0]['user_id']);
        $this->assertSame(700.0, $result['members'][0]['amounts'][0]['current']);
        $this->assertSame([], app(NativeScorecardService::class)->build([], $this->period())['currencies']);
    }

    public function test_prior_only_groups_and_fallback_event_time_are_retained(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'UGX']);
        $this->payment($platform, '2026-10-25 23:59:59', ['completed_at' => null]);
        $row = app(NativeScorecardService::class)->build(null, $this->period())['currencies'][0];
        $this->assertSame('UGX', $row['currency']);
        $this->assertSame(0.0, $row['current']);
        $this->assertSame(700.0, $row['prior']);
        $this->assertSame(-100.0, $row['delta_percent']);
    }

    public function test_cfa_is_resolved_per_market_and_ambiguous_currencies_never_merge(): void
    {
        foreach ([['Senegal', 'Senegal', 'XOF'], ['Cameroon', 'Cameroon', 'XAF'], ['Test Alpha', 'unknown', 'CFA'], ['Test Beta', 'unknown', 'CFA']] as [$name, $country]) {
            $platform = Platform::factory()->create(['name' => $name, 'country' => $country, 'currency_code' => 'CFA']);
            $this->payment($platform, '2026-10-27 12:00:00');
        }
        $rows = app(NativeScorecardService::class)->build(null, $this->period())['currencies'];
        $this->assertCount(4, $rows);
        $this->assertContains('XOF', array_column($rows, 'key'));
        $this->assertContains('XAF', array_column($rows, 'key'));
        $unknown = array_values(array_filter($rows, fn ($row) => $row['unresolved']));
        $this->assertCount(2, $unknown);
        $this->assertNotSame($unknown[0]['key'], $unknown[1]['key']);
        $this->assertSame(700.0, $unknown[0]['current']);
    }

    public function test_invalid_archive_dates_are_refused_instead_of_expanding_scope(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(NativeScorecardService::class)->build(null, array_merge($this->period(), ['to' => '2026-02-30']));
    }
}
