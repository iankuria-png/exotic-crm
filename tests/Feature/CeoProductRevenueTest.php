<?php

namespace Tests\Feature;

use App\Models\MarketRevenueTarget;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\Mcp\RevenueReportingQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CeoProductRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(9, 0)); // noon EAT, Monday
        Http::preventStrayRequests();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_ceo' => true, 'status' => 'active']));
    }

    private function payment(Platform $platform, array $attributes = []): Payment
    {
        static $receipt = 0;

        return Payment::factory()->create(array_merge([
            'platform_id' => $platform->id, 'product_id' => null, 'amount' => 10, 'currency' => 'USD', 'status' => 'completed',
            'source' => 'gateway', 'provider_key' => 'pawapay', 'provider_environment' => 'live',
            'record_classification' => 'live', 'purpose' => 'subscription',
            'completed_at' => '2026-11-23 08:00:00', 'created_at' => '2026-11-23 07:00:00',
            'transaction_reference' => 'CEO-CASH-'.++$receipt,
        ], $attributes));
    }

    private function ledger(Payment $payment, string $type): void
    {
        $id = DB::table('wallet_transactions')->insertGetId([
            'platform_id' => $payment->platform_id, 'client_id' => $payment->client_id ?: \App\Models\Client::factory()->create(['platform_id' => $payment->platform_id])->id, 'type' => $type,
            'currency_code' => $payment->currency, 'amount' => $payment->amount, 'balance_after' => 100,
            'description' => 'CEO cash regression fixture', 'payment_id' => $payment->id, 'reference_type' => 'test', 'reference_id' => $payment->id,
            'idempotency_key' => 'ceo-ledger-'.$payment->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payment->update(['wallet_transaction_id' => $id]);
    }

    public function test_external_content_and_gift_credits_are_cash_and_later_wallet_spending_is_not(): void
    {
        $platform = Platform::factory()->create(['country' => 'Kenya', 'currency_code' => 'USD']);
        $content = $this->payment($platform, ['purpose' => 'premium_content_sale', 'amount' => 20]);
        $gift = $this->payment($platform, ['purpose' => 'send_love', 'amount' => 5]);
        $this->ledger($content, 'credit');
        $this->ledger($gift, 'credit');
        $renewal = $this->payment($platform, ['source' => 'wallet', 'subscription_lifecycle' => 'renewal', 'amount' => 15]);
        $pass = $this->payment($platform, ['source' => 'crm_wallet', 'purpose' => 'monetize_pass', 'amount' => 10]);
        $this->ledger($renewal, 'debit');
        $this->ledger($pass, 'debit');
        $this->payment($platform, ['amount' => 40, 'subscription_lifecycle' => 'renewal']);
        $this->payment($platform, ['amount' => 10]);
        $this->payment($platform, ['amount' => 25, 'completed_at' => '2026-10-24 08:00:00']);
        $data = $this->getJson('/api/crm/dashboard/ceo/product-revenue')->assertOk()->json();
        $products = collect($data['products'])->keyBy('key');
        $this->assertEquals(75, $data['selected']['normalized_total']);
        $this->assertSame(4, $data['selected']['payments_count']);
        $this->assertEquals(50, $products['subscription']['normalized_total']);
        $this->assertSame(2, $products['subscription']['payments_count']);
        $this->assertEquals(100, $products['subscription']['change_percent']);
        $this->assertEquals(20, $products['premium_content_sale']['normalized_total']);
        $this->assertEquals(5, $products['send_love']['normalized_total']);
        $this->assertEquals(0, $products['passes']['normalized_total']);
        $this->assertEquals(10, $products['passes']['wallet_funded']['normalized_total']);
        $this->assertEquals(75, collect($data['products'])->sum('normalized_total'));
        $this->assertEquals(75, collect($data['markets'])->sum('normalized_total'));
        $row = app(RevenueReportingQuery::class)->rows()->where('reported.id', $content->id)->first();
        $this->assertSame($content->transaction_reference, $row->receipt_key);
        $this->assertSame('new_cash', $row->funding);
    }

    public function test_scope_time_boundaries_calendar_periods_and_equal_elapsed_prior_are_explicit(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $other = Platform::factory()->create(['currency_code' => 'USD']);
        $this->payment($platform, ['amount' => 11, 'completed_at' => '2026-11-22 21:00:00']);
        $this->payment($platform, ['amount' => 7, 'completed_at' => '2026-11-22 20:59:59']);
        $this->payment($platform, ['amount' => 500, 'completed_at' => '2026-11-23 09:00:01']);
        $this->payment($other, ['amount' => 100]);
        $data = $this->getJson('/api/crm/dashboard/ceo/product-revenue?horizon=today&platform_id='.$platform->id)->assertOk()->json();
        $this->assertEquals(11, $data['selected']['normalized_total']);
        $this->assertEquals(11, $data['today']['normalized_total']);
        $this->assertEquals(11, $data['week']['normalized_total']);
        $this->assertEquals(18, $data['month']['normalized_total']);
        $this->assertSame('2026-11-23T00:00:00+03:00', $data['window']['from']);
        $this->assertSame('2026-11-22T12:00:00+03:00', $data['prior_window']['to']);
        $this->assertCount(1, $data['markets']);
        $this->assertTrue($data['window']['partial']);
        $historical = $this->getJson('/api/crm/dashboard/ceo/product-revenue?horizon=custom&from=2026-11-22&to=2026-11-22&platform_id='.$platform->id)->assertOk()->json();
        $this->assertEquals(7, $historical['selected']['normalized_total']);
        $this->assertEquals(11, $historical['today']['normalized_total']);
        $this->assertFalse($historical['window']['partial']);
    }

    public function test_eat_midnight_accepts_the_local_calendar_date_and_an_empty_elapsed_interval(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-22 21:00:00', 'UTC'));
        $data = $this->getJson('/api/crm/dashboard/ceo/product-revenue?horizon=today&date=2026-11-23')->assertOk()->json();
        $this->assertEquals(0, $data['selected']['normalized_total']);
        $this->assertSame(0, $data['selected']['payments_count']);
        $this->assertSame('2026-11-23T00:00:00+03:00', $data['as_of']);
        $this->assertSame($data['window']['from'], $data['window']['to']);
    }

    public function test_currency_missing_fx_country_rollups_and_cent_partitions(): void
    {
        $a = Platform::factory()->create(['country' => 'Rwanda', 'currency_code' => 'RWF']);
        $b = Platform::factory()->create(['country' => 'RW', 'currency_code' => 'USD']);
        $this->payment($a, ['currency' => 'RWF', 'amount' => 100]);
        $this->payment($b, ['currency' => 'USD', 'amount' => 20]);
        $data = $this->getJson('/api/crm/dashboard/ceo/product-revenue')->assertOk()->json();
        $this->assertNull($data['selected']['normalized_total']);
        $this->assertEquals(['RWF' => 100, 'USD' => 20], $data['selected']['native']);
        $this->assertSame(1, $data['selected']['fx_coverage']['missing_payments_count']);
        $this->assertCount(1, $data['markets']);
        $this->assertCount(2, $data['markets'][0]['platforms']);
        $this->assertNull($data['products'][0]['share_percent']);
        DB::table('reporting_fx_rates')->insert(['provider' => 'manual', 'source_currency' => 'RWF', 'target_currency' => 'KES', 'rate_date' => '2026-11-23', 'rate' => 0.123456, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('reporting_fx_rates')->insert(['provider' => 'manual', 'source_currency' => 'USD', 'target_currency' => 'KES', 'rate_date' => '2026-11-23', 'rate' => 130, 'created_at' => now(), 'updated_at' => now()]);
        $kes = $this->getJson('/api/crm/dashboard/ceo/product-revenue?reporting_currency=KES')->assertOk()->json();
        $this->assertSame('KES', $kes['selected']['normalized_currency']);
        $this->assertEquals(2612.35, $kes['selected']['normalized_total']);
        $this->assertEquals($kes['selected']['normalized_total'], collect($kes['products'])->sum('normalized_total'));
        $this->assertEquals($kes['selected']['normalized_total'], collect($kes['markets'][0]['platforms'])->sum('normalized_total'));
    }

    public function test_targets_use_their_own_calendar_cadence_and_can_exceed_one_hundred_percent(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $this->payment($platform, ['amount' => 150]);
        $this->payment($platform, ['amount' => 500, 'completed_at' => '2026-11-20 12:00:00']);
        MarketRevenueTarget::create(['platform_id' => $platform->id, 'period' => 'weekly', 'target_currency' => 'USD', 'target' => 100, 'set_by' => auth()->id()]);
        $data = $this->getJson('/api/crm/dashboard/ceo/product-revenue')->assertOk()->json();
        $goal = $data['markets'][0]['platforms'][0]['targets'][0];
        $this->assertEquals(650, $data['selected']['normalized_total']);
        $this->assertEquals(150, $goal['actual']['normalized_total']);
        $this->assertEquals(150, $goal['percentage']);
        $this->assertSame('weekly', $goal['period']);
    }

    public function test_auth_and_invalid_scopes_or_ranges_are_rejected(): void
    {
        $this->getJson('/api/crm/dashboard/ceo/product-revenue?platform_id=no')->assertUnprocessable();
        $this->getJson('/api/crm/dashboard/ceo/product-revenue?platform_id=999999')->assertUnprocessable();
        $this->getJson('/api/crm/dashboard/ceo/product-revenue?horizon=custom&from=2026-11-24&to=2026-11-22')->assertUnprocessable();
        $this->getJson('/api/crm/dashboard/ceo/product-revenue?horizon=custom')->assertUnprocessable();
        $this->getJson('/api/crm/dashboard/ceo/product-revenue?horizon=today&date=2026-11-24')->assertUnprocessable();
        $this->getJson('/api/crm/dashboard/ceo/product-revenue?horizon=bad')->assertUnprocessable();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'is_ceo' => false]));
        $this->getJson('/api/crm/dashboard/ceo/product-revenue')->assertForbidden();
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'is_ceo' => true]));
        $this->getJson('/api/crm/dashboard/ceo/product-revenue')->assertForbidden();
    }

    public function test_credit_receipt_collisions_and_exclusions_preserve_view_parity_and_exact_rollback(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $content = $this->payment($platform, ['purpose' => 'premium_content_sale', 'transaction_reference' => 'COLLISION']);
        $this->ledger($content, 'credit');
        $this->payment($platform, ['transaction_reference' => 'COLLISION']);
        foreach ([['resolution_code' => 'refunded'], ['source' => 'wallet'], ['purpose' => 'wallet_topup'], ['amount' => 0], ['provider_environment' => 'sandbox', 'record_classification' => 'test']] as $excluded) {
            $this->payment($platform, $excluded);
        }
        $this->payment($platform, ['amount' => 12, 'purpose' => 'send_love']);
        $data = $this->getJson('/api/crm/dashboard/ceo/product-revenue')->assertOk()->json();
        $this->assertEquals(12, $data['selected']['normalized_total']);
        $this->assertSame(2, $data['coverage']['ambiguous_receipts']['payments_count']);
        $view = app(RevenueReportingQuery::class)->aggregateView();
        $pdo = DB::getPdo();
        $bindings = array_map(fn ($v) => is_numeric($v) ? (string) $v : $pdo->quote((string) $v), $view->getBindings());
        DB::statement('DROP VIEW IF EXISTS '.RevenueReportingQuery::VIEW);
        DB::statement('CREATE VIEW '.RevenueReportingQuery::VIEW.' AS '.\Illuminate\Support\Str::replaceArray('?', $bindings, $view->toSql()));
        $preimage = DB::selectOne('SELECT sql FROM sqlite_master WHERE name = ?', [RevenueReportingQuery::VIEW])->sql;
        $migration = require database_path('migrations/2026_11_23_180000_refresh_mcp_payment_collections_funding.php');
        $migration->up();
        $this->assertEquals(12, DB::table(RevenueReportingQuery::VIEW)->sum('amount'));
        $this->assertEquals(1, DB::table(RevenueReportingQuery::VIEW)->sum('payments_count'));
        $this->assertCount(12, DB::select('PRAGMA table_info('.RevenueReportingQuery::VIEW.')'));
        $migration->down();
        $this->assertSame($preimage, DB::selectOne('SELECT sql FROM sqlite_master WHERE name = ?', [RevenueReportingQuery::VIEW])->sql);
    }
}
