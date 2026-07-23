<?php

namespace Tests\Feature;

use App\Models\ClientActiveSnapshot;
use App\Models\AgentGoalOverride;
use App\Models\Client;
use App\Models\Deal;
use App\Models\MarketRevenueTarget;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ReportingFxRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CeoDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_ceo_dashboard_endpoints_are_server_side_gated(): void
    {
        $admin = $this->user(['role' => 'admin', 'is_ceo' => false]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/crm/dashboard/ceo/summary')
            ->assertForbidden();

        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true, 'status' => 'active']);
        Sanctum::actingAs($ceo);

        $this->getJson('/api/crm/dashboard/ceo/summary')
            ->assertOk()
            ->assertJsonPath('metrics.collected_revenue.label', 'Collected Revenue');
    }

    public function test_ceo_flag_requires_active_admin_in_auth_payload_and_api_gate(): void
    {
        $salesTaggedAsCeo = $this->user(['role' => 'sales', 'is_ceo' => true, 'status' => 'active']);
        Sanctum::actingAs($salesTaggedAsCeo);

        $this->getJson('/api/crm/me')
            ->assertOk()
            ->assertJsonPath('user.is_ceo', false);

        $this->getJson('/api/crm/dashboard/ceo/summary')
            ->assertForbidden();
    }

    public function test_revenue_uses_completed_at_date_basis_and_recent_payments_only_include_completed(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Nairobi',
            'country' => 'Kenya',
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true]);
        Sanctum::actingAs($ceo);

        ClientActiveSnapshot::query()->create([
            'date' => '2026-05-01',
            'platform_id' => $platform->id,
            'count' => 10,
            'created_at' => now(),
        ]);
        ClientActiveSnapshot::query()->create([
            'date' => '2026-05-10',
            'platform_id' => $platform->id,
            'count' => 12,
            'created_at' => now(),
        ]);

        $this->payment($platform, $product, [
            'amount' => 100,
            'status' => 'completed',
            'created_at' => '2026-05-09 12:00:00',
            'completed_at' => '2026-05-10 12:00:00',
        ]);
        $this->payment($platform, $product, [
            'amount' => 200,
            'status' => 'completed',
            'created_at' => '2026-05-10 12:00:00',
            'completed_at' => '2026-05-11 12:00:00',
        ]);
        $this->payment($platform, $product, [
            'amount' => 300,
            'status' => 'expired',
            'created_at' => '2026-05-10 13:00:00',
            'completed_at' => '2026-05-10 13:00:00',
        ]);

        $summary = $this->getJson('/api/crm/dashboard/ceo/summary?horizon=custom&from=2026-05-10&to=2026-05-10&reporting_currency=USD')
            ->assertOk()
            ->json();

        $this->assertSame(400.0, (float) data_get($summary, 'metrics.collected_revenue.value.normalized_total'));
        $this->assertSame(2, (int) data_get($summary, 'metrics.collected_revenue.value.payments_count'));
        $this->assertSame(12, (int) data_get($summary, 'metrics.active_clients.value.count'));

        $recent = $this->getJson('/api/crm/dashboard/ceo/recent-payments?platform_id=' . $platform->id . '&reporting_currency=USD')
            ->assertOk()
            ->json('payments');

        $this->assertCount(2, $recent);
        $this->assertSame([200.0, 100.0], array_map(fn ($payment) => (float) $payment['amount'], $recent));
    }

    public function test_market_pie_and_recent_payments_expose_channel_controls(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Accra',
            'country' => 'Ghana',
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true]);
        Sanctum::actingAs($ceo);

        $this->payment($platform, $product, [
            'amount' => 100,
            'source' => 'gateway',
            'provider_key' => 'pawapay',
            'completed_at' => now()->subMinutes(5),
        ]);
        $this->payment($platform, $product, [
            'amount' => 50,
            'source' => 'manual',
            'provider_key' => null,
            'completed_at' => now()->subMinutes(10),
        ]);
        $this->payment($platform, $product, [
            'amount' => 25,
            'source' => 'import',
            'provider_key' => null,
            'completed_at' => now()->subMinutes(15),
        ]);

        $pie = $this->getJson('/api/crm/dashboard/ceo/market-pie?reporting_currency=USD')
            ->assertOk()
            ->json();

        $this->assertEqualsCanonicalizing(['self_service', 'manual', 'other'], array_column($pie['channels'], 'key'));
        $this->assertSame(['self_service', 'manual', 'other'], array_column($pie['markets'][0]['channels'], 'key'));

        $manual = $this->getJson('/api/crm/dashboard/ceo/recent-payments?limit=20&channel=manual&reporting_currency=USD')
            ->assertOk()
            ->assertJsonPath('limit', 20)
            ->assertJsonPath('channel_filter', 'manual')
            ->json('payments');

        $this->assertCount(1, $manual);
        $this->assertSame('manual', $manual[0]['channel']['key']);
        $this->assertSame(50.0, (float) $manual[0]['amount']);
    }

    public function test_summary_uses_customer_revenue_mix_instead_of_ambiguous_activation_rate(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Lagos',
            'country' => 'Nigeria',
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true]);
        Sanctum::actingAs($ceo);

        $newClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'created_at' => '2026-05-03 10:00:00',
        ]);
        $existingClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'created_at' => '2026-04-15 10:00:00',
        ]);

        $this->payment($platform, $product, [
            'client_id' => $newClient->id,
            'amount' => 250,
            'completed_at' => '2026-05-10 12:00:00',
        ]);
        $this->payment($platform, $product, [
            'client_id' => $existingClient->id,
            'amount' => 750,
            'completed_at' => '2026-05-10 12:05:00',
        ]);

        $summary = $this->getJson('/api/crm/dashboard/ceo/summary?horizon=custom&from=2026-05-01&to=2026-05-31&reporting_currency=USD')
            ->assertOk()
            ->json();

        $this->assertSame('New User Revenue', data_get($summary, 'metrics.new_user_revenue.label'));
        $this->assertSame('Existing User Revenue', data_get($summary, 'metrics.existing_user_revenue.label'));
        $this->assertSame(250.0, (float) data_get($summary, 'metrics.new_user_revenue.value.normalized_amount'));
        $this->assertSame(750.0, (float) data_get($summary, 'metrics.existing_user_revenue.value.normalized_amount'));
        $this->assertSame(25.0, (float) data_get($summary, 'customer_mix.buckets.new_active.share_percent'));
        $this->assertSame(75.0, (float) data_get($summary, 'customer_mix.buckets.existing_active.share_percent'));
    }

    public function test_collection_channels_use_routing_and_manual_proof_signals(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Abuja',
            'country' => 'Nigeria',
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        $client = Client::factory()->create(['platform_id' => $platform->id]);
        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true]);
        Sanctum::actingAs($ceo);

        $selfService = $this->payment($platform, $product, [
            'amount' => 100,
            'source' => 'import',
            'provider_key' => null,
            'completed_at' => now()->subMinutes(5),
        ]);
        DB::table('billing_routing_decisions')->insert([
            'payment_id' => $selfService->id,
            'market_id' => $platform->id,
            'billing_surface' => 'subscription',
            'provider_type_key' => 'pawapay',
            'execution_mode' => 'proxy',
            'environment' => 'production',
            'snapshot_json' => json_encode(['provider_key' => 'pawapay']),
            'created_at' => now(),
        ]);

        $manualProof = $this->payment($platform, $product, [
            'client_id' => $client->id,
            'amount' => 80,
            'source' => 'import',
            'provider_key' => null,
            'completed_at' => now()->subMinutes(10),
        ]);
        DB::table('payment_manual_submissions')->insert([
            'payment_id' => $manualProof->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'duration_key' => 'weekly',
            'manual_method_key' => 'bank_transfer',
            'activated_on_submit' => true,
            'sender_name' => 'Customer',
            'transaction_reference' => 'MANUAL-PROOF-1',
            'proof_path' => 'proofs/example.jpg',
            'proof_mime' => 'image/jpeg',
            'proof_size_bytes' => 1234,
            'review_decision' => 'approved',
            'reviewed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacyAgentEntry = $this->payment($platform, $product, [
            'amount' => 60,
            'source' => 'gateway',
            'provider_key' => null,
            'transaction_reference' => 'UERLP5FZJE',
            'reference_number' => 'UERLP5FZJE',
            'match_confidence' => 'manual',
            'confirmed_by' => $ceo->id,
            'confirmed_at' => now(),
            'reconciliation_state' => 'resolved',
            'completed_at' => now()->subMinutes(15),
        ]);

        $legacyUnknown = $this->payment($platform, $product, [
            'amount' => 40,
            'source' => 'gateway',
            'provider_key' => null,
            'transaction_reference' => 'LEGACY-UNKNOWN-1',
            'reference_number' => 'LEGACY-UNKNOWN-1',
            'completed_at' => now()->subMinutes(20),
        ]);

        $pie = $this->getJson('/api/crm/dashboard/ceo/market-pie?reporting_currency=USD')
            ->assertOk()
            ->json();

        $this->assertEqualsCanonicalizing(['self_service', 'manual', 'other'], array_column($pie['channels'], 'key'));

        $manual = $this->getJson('/api/crm/dashboard/ceo/recent-payments?channel=manual&reporting_currency=USD')
            ->assertOk()
            ->json('payments');

        $this->assertCount(2, $manual);
        $this->assertEqualsCanonicalizing([$manualProof->id, $legacyAgentEntry->id], array_column($manual, 'id'));
        $this->assertTrue(collect($manual)->every(fn (array $payment) => $payment['channel']['key'] === 'manual'));

        $selfServiceRows = $this->getJson('/api/crm/dashboard/ceo/recent-payments?channel=self_service&reporting_currency=USD')
            ->assertOk()
            ->json('payments');
        $this->assertSame([$selfService->id], array_column($selfServiceRows, 'id'));

        $otherRows = $this->getJson('/api/crm/dashboard/ceo/recent-payments?channel=other&reporting_currency=USD')
            ->assertOk()
            ->json('payments');
        $this->assertSame([$legacyUnknown->id], array_column($otherRows, 'id'));
    }

    public function test_agent_performance_surfaces_weekly_individual_revenue_targets_in_month_window(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true]);
        $agent = $this->user([
            'name' => 'Benjamin Kiura',
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
        ]);
        $agent->platforms()->sync([$platform->id]);
        Sanctum::actingAs($ceo);

        $client = Client::factory()->create(['platform_id' => $platform->id, 'assigned_to' => $agent->id]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'assigned_to' => $agent->id,
            'amount' => 300,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $this->payment($platform, $product, [
            'client_id' => $client->id,
            'deal_id' => $deal->id,
            'amount' => 300,
            'status' => 'completed',
            'created_at' => '2026-05-12 12:00:00',
            'completed_at' => '2026-05-12 12:00:00',
        ]);

        AgentGoalOverride::query()->create([
            'user_id' => $agent->id,
            'platform_id' => $platform->id,
            'metric' => 'revenue',
            'target' => 5000,
            'target_currency' => 'USD',
            'period' => 'weekly',
            'set_by' => $ceo->id,
        ]);

        $response = $this->getJson('/api/crm/dashboard/ceo/agent-performance?horizon=custom&from=2026-05-01&to=2026-05-31&reporting_currency=USD')
            ->assertOk();

        $benjamin = collect($response->json('agents'))->firstWhere('id', $agent->id);

        $this->assertNotNull($benjamin);
        $this->assertSame('weekly', data_get($benjamin, 'target.period'));
        $this->assertSame(5000.0, (float) data_get($benjamin, 'target.target'));
        $this->assertSame(300.0, (float) data_get($benjamin, 'target.current'));
        $this->assertSame(6, (int) data_get($benjamin, 'target.percentage'));
    }

    public function test_country_revenue_surfaces_individual_market_revenue_targets(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        $admin = $this->user(['role' => 'admin']);
        $agent = $this->user([
            'name' => 'Benjamin Kiura',
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
        ]);
        $agent->platforms()->sync([$platform->id]);
        Sanctum::actingAs($admin);

        $client = Client::factory()->create(['platform_id' => $platform->id, 'assigned_to' => $agent->id]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'assigned_to' => $agent->id,
            'amount' => 300,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $this->payment($platform, $product, [
            'client_id' => $client->id,
            'deal_id' => $deal->id,
            'amount' => 300,
            'status' => 'completed',
            'created_at' => '2026-05-12 12:00:00',
            'completed_at' => '2026-05-12 12:00:00',
        ]);

        AgentGoalOverride::query()->create([
            'user_id' => $agent->id,
            'platform_id' => $platform->id,
            'metric' => 'revenue',
            'target' => 5000,
            'target_currency' => 'USD',
            'period' => 'weekly',
            'set_by' => $admin->id,
        ]);

        $response = $this->getJson('/api/crm/dashboard/country-revenue?from=2026-05-01&to=2026-05-31&country_period=month&currency_mode=flat&reporting_currency=USD')
            ->assertOk();

        $kenya = collect($response->json())->firstWhere('platform_id', $platform->id);

        $this->assertNotNull($kenya);
        $this->assertSame('weekly', data_get($kenya, 'target.period'));
        $this->assertSame(5000.0, (float) data_get($kenya, 'target.target'));
        $this->assertSame(300.0, (float) data_get($kenya, 'target.current'));
        $this->assertSame(6, (int) data_get($kenya, 'target.percentage'));
    }

    public function test_country_revenue_prefers_explicit_market_target_over_agent_allocations(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'USD',
        ]);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        $admin = $this->user(['role' => 'admin']);
        $agent = $this->user([
            'name' => 'Benjamin Kiura',
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
        ]);
        $agent->platforms()->sync([$platform->id]);
        Sanctum::actingAs($admin);

        $client = Client::factory()->create(['platform_id' => $platform->id, 'assigned_to' => $agent->id]);
        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'assigned_to' => $agent->id,
            'amount' => 300,
            'currency' => 'USD',
            'status' => 'active',
        ]);

        $this->payment($platform, $product, [
            'client_id' => $client->id,
            'deal_id' => $deal->id,
            'amount' => 300,
            'status' => 'completed',
            'created_at' => '2026-05-12 12:00:00',
            'completed_at' => '2026-05-12 12:00:00',
        ]);

        AgentGoalOverride::query()->create([
            'user_id' => $agent->id,
            'platform_id' => $platform->id,
            'metric' => 'revenue',
            'target' => 5000,
            'target_currency' => 'USD',
            'period' => 'monthly',
            'set_by' => $admin->id,
        ]);
        MarketRevenueTarget::query()->create([
            'platform_id' => $platform->id,
            'period' => 'monthly',
            'target' => 10000,
            'target_currency' => 'USD',
            'set_by' => $admin->id,
        ]);

        $response = $this->getJson('/api/crm/dashboard/country-revenue?from=2026-05-01&to=2026-05-31&country_period=month&currency_mode=flat&reporting_currency=USD')
            ->assertOk();

        $kenya = collect($response->json())->firstWhere('platform_id', $platform->id);

        $this->assertSame('market_target', data_get($kenya, 'target.source'));
        $this->assertSame(10000.0, (float) data_get($kenya, 'target.target'));
        $this->assertSame(3, (int) data_get($kenya, 'target.percentage'));
    }

    public function test_today_view_uses_nairobi_day_same_time_yesterday_prior_and_hourly_trend(): void
    {
        // 2026-07-22 11:30 UTC == 14:30 Africa/Nairobi. Current Nairobi hour = 14.
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-07-22 11:30:00', 'UTC'));

        $platform = Platform::factory()->create(['name' => 'Nairobi', 'country' => 'Kenya', 'currency_code' => 'USD']);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'currency' => 'USD']);
        $ceo = $this->user(['role' => 'admin', 'is_ceo' => true]);
        Sanctum::actingAs($ceo);

        // Today 08:00 Nairobi (05:00 UTC) — inside today, before "now".
        $this->payment($platform, $product, ['amount' => 100, 'status' => 'completed', 'created_at' => '2026-07-22 05:00:00', 'completed_at' => '2026-07-22 05:00:00']);
        // Yesterday 08:00 Nairobi (05:00 UTC) — before the same clock time → counts in same-time-yesterday prior.
        $this->payment($platform, $product, ['amount' => 50, 'status' => 'completed', 'created_at' => '2026-07-21 05:00:00', 'completed_at' => '2026-07-21 05:00:00']);
        // Yesterday 20:00 Nairobi (17:00 UTC) — AFTER the same clock time → excluded from summary prior, present in the full-day trend ghost.
        $this->payment($platform, $product, ['amount' => 999, 'status' => 'completed', 'created_at' => '2026-07-21 17:00:00', 'completed_at' => '2026-07-21 17:00:00']);

        $summary = $this->getJson('/api/crm/dashboard/ceo/summary?horizon=today&reporting_currency=USD')->assertOk()->json();

        $this->assertTrue((bool) data_get($summary, 'window.is_single_day'));
        $this->assertTrue((bool) data_get($summary, 'window.is_today'));
        $this->assertSame('2026-07-22', data_get($summary, 'window.day_date'));
        $this->assertSame(100.0, (float) data_get($summary, 'metrics.collected_revenue.value.normalized_total'));
        // Same-time-yesterday: excludes the 20:00 payment.
        $this->assertSame(50.0, (float) data_get($summary, 'metrics.collected_revenue.prior_value.normalized_total'));

        $keys = array_column($summary['insights'], 'key');
        $this->assertContains('avg_daily', $keys);
        $this->assertNotContains('cash_velocity', $keys);

        $trend = $this->getJson('/api/crm/dashboard/ceo/revenue-trend?horizon=today&reporting_currency=USD')->assertOk()->json();
        $this->assertSame('hour', $trend['bucket']);
        $this->assertCount(24, $trend['points']);

        $byLabel = collect($trend['points'])->keyBy('label');
        // 08:00 — today value present, prior = yesterday 08:00.
        $this->assertSame(100.0, (float) $byLabel['08:00']['value']);
        $this->assertSame(50.0, (float) $byLabel['08:00']['prior_value']);
        $this->assertFalse((bool) $byLabel['08:00']['future']);
        // 20:00 — future today (hour 20 > 14), but the prior-day ghost keeps the 20:00 payment.
        $this->assertTrue((bool) $byLabel['20:00']['future']);
        $this->assertSame(999.0, (float) $byLabel['20:00']['prior_value']);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_summary_memoizes_fx_lookups_and_still_resolves_leading_market(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $kenya = Platform::factory()->create(['name' => 'Nairobi', 'country' => 'Kenya', 'currency_code' => 'KES']);
        $kenyaProduct = Product::factory()->create(['platform_id' => $kenya->id, 'currency' => 'KES']);
        $tanzania = Platform::factory()->create(['name' => 'Dar', 'country' => 'Tanzania', 'currency_code' => 'TZS']);
        $tzProduct = Product::factory()->create(['platform_id' => $tanzania->id, 'currency' => 'TZS']);
        Sanctum::actingAs($this->user(['role' => 'admin', 'is_ceo' => true]));

        // Only 4 distinct (currency, date) pairs exist, but many payment row-groups reference them.
        foreach (['KES' => 0.0077, 'TZS' => 0.0004] as $currency => $rate) {
            foreach (['2026-05-10', '2026-05-20'] as $date) {
                ReportingFxRate::query()->create([
                    'provider' => 'manual',
                    'source_currency' => $currency,
                    'target_currency' => 'USD',
                    'rate_date' => $date,
                    'rate' => $rate,
                    'fetched_at' => now(),
                ]);
            }
        }

        foreach (['2026-05-10 09:00:00', '2026-05-20 09:00:00'] as $ts) {
            foreach (range(1, 4) as $i) {
                $this->payment($kenya, $kenyaProduct, ['amount' => 100000, 'currency' => 'KES', 'completed_at' => $ts, 'created_at' => $ts]);
                $this->payment($tanzania, $tzProduct, ['amount' => 100000, 'currency' => 'TZS', 'completed_at' => $ts, 'created_at' => $ts]);
            }
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $summary = $this->getJson('/api/crm/dashboard/ceo/summary?horizon=custom&from=2026-05-01&to=2026-05-31&reporting_currency=USD')
            ->assertOk()->json();
        $fxQueries = collect(\Illuminate\Support\Facades\DB::getQueryLog())
            ->filter(fn (array $entry) => str_contains($entry['query'], 'reporting_fx_rates'))
            ->count();
        \Illuminate\Support\Facades\DB::disableQueryLog();

        // Memoized: at most one lookup per distinct (currency, date) pair (4), not one per
        // row-group across every aggregation. Without the memo this runs into the dozens.
        $this->assertLessThanOrEqual(6, $fxQueries, "FX lookups were not memoized: {$fxQueries} queries hit reporting_fx_rates");

        // Kenya (KES @ 0.0077) far outweighs Tanzania (TZS @ 0.0004); the leading-market insight
        // must still resolve from the lightweight path summary() now uses.
        $topMarket = collect($summary['insights'])->firstWhere('key', 'top_market');
        $this->assertNotNull($topMarket);
        $this->assertStringContainsString('Nairobi', $topMarket['message']);
    }

    public function test_single_day_hourly_trend_reconciles_to_collected_with_cfa_market(): void
    {
        config([
            'ceo.peak_hours_timezone' => 'Africa/Nairobi',
            'services.reporting_fx.enabled' => true,
        ]);

        $kenya = Platform::factory()->create(['name' => 'Nairobi', 'country' => 'Kenya', 'currency_code' => 'USD']);
        $kenyaProduct = Product::factory()->create(['platform_id' => $kenya->id, 'currency' => 'USD']);
        $senegal = Platform::factory()->create(['name' => 'Dakar', 'country' => 'Senegal', 'currency_code' => 'XOF']);
        $senegalProduct = Product::factory()->create(['platform_id' => $senegal->id, 'currency' => 'XOF']);
        Sanctum::actingAs($this->user(['role' => 'admin', 'is_ceo' => true]));

        ReportingFxRate::query()->create([
            'provider' => 'manual',
            'source_currency' => 'XOF',
            'target_currency' => 'USD',
            'rate_date' => '2026-06-10',
            'rate' => 0.002,
            'fetched_at' => now(),
        ]);

        // Past complete Nairobi day via the date stepper. 07:00 UTC = 10:00 Nairobi, 09:00 UTC = 12:00 Nairobi.
        $this->payment($kenya, $kenyaProduct, ['amount' => 100, 'currency' => 'USD', 'status' => 'completed', 'created_at' => '2026-06-10 07:00:00', 'completed_at' => '2026-06-10 07:00:00']);
        $this->payment($senegal, $senegalProduct, ['amount' => 1000, 'currency' => 'CFA', 'status' => 'completed', 'created_at' => '2026-06-10 09:00:00', 'completed_at' => '2026-06-10 09:00:00']);

        $params = 'horizon=today&date=2026-06-10&reporting_currency=USD';
        $collected = (float) $this->getJson("/api/crm/dashboard/ceo/summary?{$params}")
            ->assertOk()->json('metrics.collected_revenue.value.normalized_total');
        $trend = $this->getJson("/api/crm/dashboard/ceo/revenue-trend?{$params}")->assertOk()->json();

        $this->assertEqualsWithDelta(102.0, $collected, 0.001); // 100 USD + 1000 CFA * 0.002
        $hourlySum = collect($trend['points'])->sum(fn (array $point) => (float) $point['value']);
        $this->assertEqualsWithDelta($collected, $hourlySum, 0.001);
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
        ], $overrides));

        $payment->save();

        return $payment;
    }
}
