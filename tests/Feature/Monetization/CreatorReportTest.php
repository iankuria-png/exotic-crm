<?php

namespace Tests\Feature\Monetization;

use App\Models\Client;
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

class CreatorReportTest extends TestCase
{
    use RefreshDatabase;

    private Platform $kenya;

    private Platform $senegal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kenya = Platform::factory()->create(['name' => 'Kenya', 'country' => 'Kenya', 'currency_code' => 'KES', 'supported_currencies' => ['KES']]);
        $this->senegal = Platform::factory()->create(['name' => 'Senegal', 'country' => 'Senegal', 'currency_code' => 'GHS', 'supported_currencies' => ['GHS']]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));
    }

    public function test_pass_ledger_lists_who_bought_each_pass_and_hides_free_ones_by_default(): void
    {
        $amina = $this->creator($this->kenya, 'Amina');
        $bree = $this->creator($this->kenya, 'Bree');
        $bought = $this->pass($amina, 500, now()->subDays(2), 'active', true);
        $this->pass($bree, 0, now()->subDay(), 'granted');
        $this->pass($bree, 300, now()->subDays(40), 'expired', true);
        $this->pass($amina, 999, now(), 'active', false, true);

        $paid = $this->getJson('/api/crm/monetization/passes')->assertOk()->json();

        $this->assertSame(2, $paid['passes']['total']);
        $first = $paid['passes']['data'][0];
        $this->assertSame($bought->id, $first['id']);
        $this->assertSame('Amina', $first['client']['name']);
        $this->assertSame('Kenya', $first['platform_name']);
        $this->assertSame('paid', $first['type']);
        $this->assertSame('MP-'.$bought->id, $first['payment_reference']);
        $this->assertSame(800.0, (float) $paid['totals'][0]['paid']);
        $this->assertSame(2, $paid['totals'][0]['creators']);

        $free = $this->getJson('/api/crm/monetization/passes?pass_type=free')->assertOk()->json();
        $this->assertSame(1, $free['passes']['total']);
        $this->assertSame('free', $free['passes']['data'][0]['type']);

        $search = $this->getJson('/api/crm/monetization/passes?pass_type=all&search=Bree')->assertOk()->json();
        $this->assertSame(2, $search['passes']['total']);

        $windowed = $this->getJson('/api/crm/monetization/passes?from='.now()->subDays(7)->toDateString().'&to='.now()->toDateString())->assertOk()->json();
        $this->assertSame(1, $windowed['passes']['total']);

        $csv = $this->get('/api/crm/monetization/passes/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('Amina', $csv);
        $this->assertStringContainsString('MP-'.$bought->id, $csv);
    }

    public function test_creator_directory_filters_by_pass_state_and_sorts_money_across_markets(): void
    {
        ReportingFxRate::create(['provider' => 'currencyapi', 'source_currency' => 'KES', 'target_currency' => 'USD', 'rate_date' => now()->toDateString(), 'rate' => 0.01, 'fetched_at' => now()]);
        ReportingFxRate::create(['provider' => 'currencyapi', 'source_currency' => 'GHS', 'target_currency' => 'USD', 'rate_date' => now()->toDateString(), 'rate' => 0.1, 'fetched_at' => now()]);
        $active = $this->creator($this->kenya, 'Amina');
        $expiring = $this->creator($this->kenya, 'Bree');
        $unclaimed = $this->creator($this->kenya, 'Cleo');
        $ended = $this->creator($this->senegal, 'Dina');
        $this->pass($active, 2000, now()->subDays(3), 'active', true, false, now()->addDays(20));
        $this->pass($expiring, 0, now()->subDays(25), 'active', false, false, now()->addDays(3));
        $this->pass($unclaimed, 0, now()->subDay(), 'granted', false, false, now()->addDays(30));
        $this->pass($ended, 500, now()->subDays(50), 'expired', true, false, now()->subDays(20));
        $this->sale($active, 1500);
        $this->sale($ended, 400);

        $all = $this->getJson('/api/crm/monetization/creators?sort=pass_paid&direction=desc&reporting_currency=USD')->assertOk()->json();

        $this->assertSame(['all' => 4, 'active' => 1, 'expiring' => 1, 'unclaimed' => 1, 'held' => 0, 'ended' => 1], $all['counts']);
        // 500 GHS (50 USD) outranks 2,000 KES (20 USD) once converted.
        $this->assertSame(['Dina', 'Amina'], array_slice(array_column($all['data'], 'name'), 0, 2));
        $amina = collect($all['data'])->firstWhere('name', 'Amina');
        $this->assertSame('active', $amina['state']);
        $this->assertSame(1, $amina['paid_passes']);
        $this->assertSame(1500.0, (float) $amina['sales_gross']);
        $this->assertNotNull($amina['stats']);
        $this->assertSame($active->id, $amina['pass']['client_id']);

        $expiringOnly = $this->getJson('/api/crm/monetization/creators?state=expiring')->assertOk()->json();
        $this->assertSame(['Bree'], array_column($expiringOnly['data'], 'name'));

        $paidOnly = $this->getJson('/api/crm/monetization/creators?pass_type=paid&sort=name&direction=asc')->assertOk()->json();
        $this->assertSame(['Amina', 'Dina'], array_column($paidOnly['data'], 'name'));

        $searched = $this->getJson('/api/crm/monetization/creators?search=cle')->assertOk()->json();
        $this->assertSame(['Cleo'], array_column($searched['data'], 'name'));

        $scoped = $this->getJson("/api/crm/monetization/creators?platform_id={$this->senegal->id}")->assertOk()->json();
        $this->assertSame(['Dina'], array_column($scoped['data'], 'name'));

        $csv = $this->get('/api/crm/monetization/creators/export?state=active')->assertOk()->streamedContent();
        $this->assertStringContainsString('Amina', $csv);
        $this->assertStringNotContainsString('Cleo', $csv);
    }

    public function test_sales_staff_only_see_assigned_markets(): void
    {
        $this->pass($this->creator($this->kenya, 'Amina'), 500, now(), 'active', true);
        $this->pass($this->creator($this->senegal, 'Dina'), 500, now(), 'active', true);
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$this->kenya->id]]));

        $this->assertSame(['Amina'], array_column($this->getJson('/api/crm/monetization/creators')->assertOk()->json('data'), 'name'));
        $this->assertSame(1, $this->getJson('/api/crm/monetization/passes')->assertOk()->json('passes.total'));
        $this->getJson("/api/crm/monetization/passes?platform_id={$this->senegal->id}")->assertForbidden();
    }

    private function creator(Platform $platform, string $name): Client
    {
        return Client::factory()->create(['platform_id' => $platform->id, 'name' => $name, 'wallet_currency' => $platform->currency_code]);
    }

    private function pass(Client $client, float $paid, $at, string $status, bool $withPayment = false, bool $sandbox = false, $expires = null): ClientMonetizationPass
    {
        $pass = ClientMonetizationPass::forceCreate(['client_id' => $client->id, 'platform_id' => $client->platform_id, 'status' => $status, 'starts_at' => $at, 'expires_at' => $expires ?? $at->copy()->addMonth(), 'duration_key' => '1_month', 'duration_days' => 30, 'currency' => $client->platform->currency_code, 'list_amount' => max($paid, 500), 'subsidy_amount' => max(0, 500 - $paid), 'paid_amount' => $paid, 'grant_source' => $paid > 0 ? 'wallet' : 'policy_new_subscription', 'eligibility_snapshot_json' => [], 'is_sandbox' => $sandbox, 'idempotency_key_hash' => hash('sha256', (string) Str::uuid())]);
        if ($withPayment) {
            $payment = Payment::create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'purpose' => Payment::PURPOSE_MONETIZE_PASS, 'amount' => $paid, 'currency' => $pass->currency, 'status' => 'completed', 'source' => 'wallet', 'completed_at' => $at, 'transaction_reference' => 'MP-'.$pass->id]);
            $pass->payment_id = $payment->id;
        }
        $pass->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $pass;
    }

    private function sale(Client $client, float $amount): void
    {
        VisitorContentPurchase::create(['public_id' => (string) Str::uuid(), 'platform_id' => $client->platform_id, 'client_id' => $client->id, 'status' => 'active', 'offer_kind' => 'single', 'offer_version' => 1, 'currency' => $client->platform->currency_code, 'gross_amount' => $amount, 'creator_credit_amount' => $amount, 'entitlement_snapshot_json' => [], 'visitor_phone_hash' => str_repeat('d', 64), 'visitor_phone_masked' => '*******0111', 'first_device_hash' => str_repeat('b', 64), 'public_token_hash' => str_repeat('c', 64), 'idempotency_key_hash' => hash('sha256', (string) Str::uuid()), 'is_sandbox' => false]);
    }
}
