<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\ChurnAggregatorService;
use App\Services\ClientChurnStamper;
use App\Support\CrmClientChurnReason;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientChurnTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_client_is_stamped_when_profile_becomes_inactive(): void
    {
        $client = Client::factory()->create();
        Payment::factory()->create([
            'platform_id' => $client->platform_id,
            'client_id' => $client->id,
            'completed_at' => now()->subDays(10),
            'created_at' => now()->subDays(10),
        ]);

        $client->update(['profile_status' => 'private']);

        $client->refresh();

        $this->assertNotNull($client->first_activated_at);
        $this->assertNotNull($client->churned_at);
        $this->assertSame('expired_unrenewed', $client->churn_reason_code);
        $this->assertSame('profile_inactive', $client->churn_source);
    }

    public function test_churn_is_cleared_when_profile_returns_active(): void
    {
        $client = Client::factory()->create(['profile_status' => 'private']);
        Payment::factory()->create([
            'platform_id' => $client->platform_id,
            'client_id' => $client->id,
            'completed_at' => now()->subMonth(),
        ]);

        $this->assertNotNull($client->fresh()->churned_at);

        $client->update([
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
        ]);

        $this->assertNull($client->fresh()->churned_at);
    }

    public function test_generic_profile_sync_preserves_a_specific_churn_reason(): void
    {
        $client = Client::factory()->create(['profile_status' => 'private']);
        Payment::factory()->create([
            'platform_id' => $client->platform_id,
            'client_id' => $client->id,
        ]);

        $stamper = app(ClientChurnStamper::class);
        $stamper->stamp(
            $client,
            CrmClientChurnReason::CUSTOMER_REQUEST,
            'deal_cancelled',
        );
        $stamper->syncFromProfileState($client->fresh());

        $client->refresh();

        $this->assertSame(CrmClientChurnReason::CUSTOMER_REQUEST, $client->churn_reason_code);
        $this->assertSame('deal_cancelled', $client->churn_source);
    }

    public function test_backfill_matches_paid_inactive_segment_definition(): void
    {
        $platform = Platform::factory()->create();
        $inactivePaid = Client::factory()->create([
            'platform_id' => $platform->id,
            'profile_status' => 'private',
        ]);
        $activePaid = Client::factory()->create([
            'platform_id' => $platform->id,
            'profile_status' => 'publish',
        ]);
        $inactiveUnpaid = Client::factory()->create([
            'platform_id' => $platform->id,
            'profile_status' => 'private',
        ]);

        foreach ([$inactivePaid, $activePaid] as $client) {
            Payment::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $client->id,
                'completed_at' => now()->subDays(20),
            ]);
        }

        $this->artisan('crm:backfill-churn-fields', [
            '--platform' => $platform->id,
            '--limit' => 100,
        ])->assertSuccessful();

        $this->assertNotNull($inactivePaid->fresh()->churned_at);
        $this->assertNull($activePaid->fresh()->churned_at);
        $this->assertNull($inactiveUnpaid->fresh()->churned_at);
    }

    public function test_market_analytics_include_signup_only_markets_and_previous_period_comparison(): void
    {
        Carbon::setTestNow('2026-06-11 12:00:00');

        $platform = Platform::factory()->create();
        $signup = Client::factory()->create(['platform_id' => $platform->id]);
        Client::withoutTimestamps(function () use ($signup): void {
            $signup->forceFill([
                'created_at' => Carbon::parse('2026-06-10 09:00:00'),
                'updated_at' => Carbon::parse('2026-06-10 09:00:00'),
            ])->saveQuietly();
        });

        $summary = app(ChurnAggregatorService::class)->summary(
            Carbon::parse('2026-06-05'),
            Carbon::parse('2026-06-11'),
            [$platform->id],
        );

        $this->assertSame(1, $summary['totals']['signups']);
        $this->assertSame(1, $summary['durations_by_market'][0]['signup_count']);
        $this->assertSame(1, $summary['durations_by_market'][0]['net_delta']);
        $this->assertSame(1, $summary['comparison']['signups']['delta']);

        Carbon::setTestNow();
    }

    public function test_market_analytics_report_current_active_clients_and_selected_period_direction(): void
    {
        $growingMarket = Platform::factory()->create(['name' => 'Growing Market']);
        $steadyMarket = Platform::factory()->create(['name' => 'Steady Market']);
        $shrinkingMarket = Platform::factory()->create(['name' => 'Shrinking Market']);

        Client::factory()->count(2)->create([
            'platform_id' => $growingMarket->id,
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'first_activated_at' => '2026-06-09 12:00:00',
        ]);
        Client::factory()->create([
            'platform_id' => $growingMarket->id,
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'first_activated_at' => '2026-05-01 12:00:00',
        ]);
        Client::factory()->create([
            'platform_id' => $growingMarket->id,
            'profile_status' => 'private',
            'first_activated_at' => '2026-05-01 12:00:00',
            'churned_at' => '2026-06-10 18:00:00',
        ]);
        Client::factory()->create([
            'platform_id' => $steadyMarket->id,
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            'first_activated_at' => '2026-05-01 12:00:00',
            'created_at' => '2026-05-01 12:00:00',
            'updated_at' => '2026-05-01 12:00:00',
        ]);
        Client::factory()->create([
            'platform_id' => $shrinkingMarket->id,
            'profile_status' => 'private',
            'first_activated_at' => '2026-05-01 12:00:00',
            'churned_at' => '2026-06-10 18:00:00',
        ]);

        $rows = collect(app(ChurnAggregatorService::class)->durationsByMarket(
            Carbon::parse('2026-06-08'),
            Carbon::parse('2026-06-11'),
            [$growingMarket->id, $steadyMarket->id, $shrinkingMarket->id],
        ))->keyBy('platform_id');

        $this->assertSame(3, $rows[$growingMarket->id]['active_count']);
        $this->assertSame(2, $rows[$growingMarket->id]['activation_count']);
        $this->assertSame(1, $rows[$growingMarket->id]['active_movement']);
        $this->assertSame('increasing', $rows[$growingMarket->id]['active_direction']);
        $this->assertSame(1, $rows[$steadyMarket->id]['active_count']);
        $this->assertSame(0, $rows[$steadyMarket->id]['active_movement']);
        $this->assertSame('steady', $rows[$steadyMarket->id]['active_direction']);
        $this->assertSame(0, $rows[$shrinkingMarket->id]['active_count']);
        $this->assertSame(-1, $rows[$shrinkingMarket->id]['active_movement']);
        $this->assertSame('decreasing', $rows[$shrinkingMarket->id]['active_direction']);
    }

    public function test_churn_summary_estimates_daily_revenue_at_risk_from_average_ticket(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);

        Payment::factory()->count(2)->create([
            'platform_id' => $platform->id,
            'client_id' => null,
            'amount' => 15,
            'currency' => 'USD',
            'completed_at' => '2026-06-10 12:00:00',
            'created_at' => '2026-06-10 12:00:00',
        ]);

        Client::factory()->count(3)->create([
            'platform_id' => $platform->id,
            'profile_status' => 'private',
            'churned_at' => '2026-06-10 18:00:00',
        ]);

        $summary = app(ChurnAggregatorService::class)->summary(
            Carbon::parse('2026-06-10'),
            Carbon::parse('2026-06-10'),
            [$platform->id],
        );

        $this->assertSame(15.0, $summary['daily'][0]['average_ticket_usd']);
        $this->assertSame(30.0, $summary['daily'][0]['collected_revenue_usd']);
        $this->assertFalse($summary['daily'][0]['collected_partial']);
        $this->assertSame(45.0, $summary['daily'][0]['estimated_revenue_at_risk_usd']);
        $this->assertSame(45.0, $summary['revenue_at_risk']['estimated_total']);
        $this->assertSame(100.0, $summary['revenue_at_risk']['coverage_percent']);
        $this->assertSame(30.0, $summary['revenue_comparison']['collected_total']);
        $this->assertSame(45.0, $summary['revenue_comparison']['lost_total']);
        $this->assertSame(-15.0, $summary['revenue_comparison']['net_total']);
        $this->assertSame(0, $summary['revenue_comparison']['partial_days']);
        $this->assertSame('all_reportable_successful_payments', $summary['revenue_comparison']['collected_coverage_note']);
    }

    public function test_churn_revenue_estimate_is_unavailable_when_fx_data_is_missing(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'GHS']);

        Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => null,
            'amount' => 150,
            'currency' => 'GHS',
            'completed_at' => '2026-06-10 12:00:00',
            'created_at' => '2026-06-10 12:00:00',
        ]);

        Client::factory()->create([
            'platform_id' => $platform->id,
            'profile_status' => 'private',
            'churned_at' => '2026-06-10 18:00:00',
        ]);

        $summary = app(ChurnAggregatorService::class)->summary(
            Carbon::parse('2026-06-10'),
            Carbon::parse('2026-06-10'),
            [$platform->id],
        );

        $this->assertNull($summary['daily'][0]['average_ticket_usd']);
        $this->assertNull($summary['daily'][0]['collected_revenue_usd']);
        $this->assertTrue($summary['daily'][0]['collected_partial']);
        $this->assertNull($summary['daily'][0]['estimated_revenue_at_risk_usd']);
        $this->assertSame(0, $summary['revenue_at_risk']['covered_churn_count']);
        $this->assertSame(0.0, $summary['revenue_at_risk']['coverage_percent']);
        $this->assertSame(1, $summary['revenue_comparison']['partial_days']);
        $this->assertNull($summary['revenue_comparison']['net_total']);
    }

    public function test_tier_breakdown_uses_last_paid_tier_before_churn(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $basic = Product::factory()->create([
            'platform_id' => $platform->id,
            'tier' => 'basic',
            'name' => 'Basic',
            'display_name' => 'Basic',
        ]);
        $vip = Product::factory()->create([
            'platform_id' => $platform->id,
            'tier' => 'vip',
            'name' => 'VIP',
            'display_name' => 'VIP',
        ]);

        $basicClients = Client::factory()->count(2)->create([
            'platform_id' => $platform->id,
            'profile_status' => 'private',
            'churned_at' => '2026-06-10 18:00:00',
        ]);
        $vipClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'profile_status' => 'private',
            'churned_at' => '2026-06-10 18:00:00',
        ]);

        foreach ($basicClients as $client) {
            Deal::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $client->id,
                'product_id' => $basic->id,
                'plan_type' => 'basic',
                'status' => 'expired',
                'activated_at' => '2026-06-01 12:00:00',
                'created_at' => '2026-06-01 12:00:00',
            ]);
        }
        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $vipClient->id,
            'product_id' => $vip->id,
            'plan_type' => 'vip',
            'status' => 'expired',
            'activated_at' => '2026-04-01 12:00:00',
            'created_at' => '2026-04-01 12:00:00',
        ]);

        $tiers = app(ChurnAggregatorService::class)->tierBreakdown(
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-11'),
            [$platform->id],
        );

        $this->assertSame('basic', $tiers[0]['key']);
        $this->assertSame(2, $tiers[0]['churn_count']);
        $this->assertSame(100.0, $tiers[0]['early_churn_percent']);
        $this->assertSame('vip', $tiers[1]['key']);
        $this->assertSame(0.0, $tiers[1]['early_churn_percent']);
    }

    public function test_signup_source_breakdown_groups_tagged_and_existing_clients(): void
    {
        $platform = Platform::factory()->create();

        Client::factory()->count(2)->create([
            'platform_id' => $platform->id,
            'signup_source' => 'fast_signup',
            'profile_status' => 'private',
            'churned_at' => '2026-06-10 18:00:00',
        ]);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'signup_source' => 'crm_provisioned',
            'profile_status' => 'private',
            'churned_at' => '2026-06-10 18:00:00',
        ]);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'signup_source' => null,
            'profile_status' => 'private',
            'churned_at' => '2026-06-10 18:00:00',
        ]);

        $sources = app(ChurnAggregatorService::class)->signupSourceBreakdown(
            Carbon::parse('2026-06-01'),
            Carbon::parse('2026-06-11'),
            [$platform->id],
        );

        $this->assertSame('fast_signup', $sources[0]['key']);
        $this->assertSame(2, $sources[0]['churn_count']);
        $this->assertSame(50.0, $sources[0]['share_of_churn_percent']);
        $this->assertSame(1, collect($sources)->firstWhere('key', 'crm_provisioned')['churn_count']);
        $this->assertSame(1, collect($sources)->firstWhere('key', 'existing')['churn_count']);
    }

    public function test_churned_queue_uses_last_paid_plan_and_supports_filtering_sorting_and_pagination(): void
    {
        Carbon::setTestNow('2026-06-11 12:00:00');

        try {
            $admin = User::factory()->create([
                'role' => 'admin',
                'status' => 'active',
                'assigned_market_ids' => [],
            ]);
            $platform = Platform::factory()->create(['currency_code' => 'USD']);
            $basic = Product::factory()->create([
                'platform_id' => $platform->id,
                'tier' => 'basic',
                'name' => 'Basic',
                'display_name' => 'Basic',
            ]);
            $vip = Product::factory()->create([
                'platform_id' => $platform->id,
                'tier' => 'vip',
                'name' => 'VIP',
                'display_name' => 'VIP',
            ]);
            $vvip = Product::factory()->create([
                'platform_id' => $platform->id,
                'tier' => 'vvip',
                'name' => 'VVIP',
                'display_name' => 'VVIP',
            ]);

            $vipClient = Client::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'Zuri VIP Client',
                'phone_normalized' => '254700000001',
                'profile_status' => 'private',
                'churned_at' => '2026-06-10 18:00:00',
                'churn_reason_code' => 'expired_unrenewed',
                'churn_source' => 'deal_expired',
                'signup_source' => 'crm_provisioned',
            ]);
            $basicClient = Client::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'Amina Basic Client',
                'phone_normalized' => '254700000002',
                'profile_status' => 'private',
                'churned_at' => '2026-06-09 18:00:00',
                'churn_reason_code' => 'customer_request',
                'churn_source' => 'deal_cancelled',
                'signup_source' => 'fast_signup',
            ]);
            $vvipClient = Client::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'Yara VVIP Client',
                'phone_normalized' => '254700000003',
                'profile_status' => 'private',
                'churned_at' => '2026-06-10 17:00:00',
                'churn_reason_code' => 'expired_unrenewed',
                'churn_source' => 'deal_expired',
            ]);

            Deal::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $vipClient->id,
                'product_id' => $basic->id,
                'plan_type' => 'basic',
                'status' => 'expired',
                'activated_at' => '2026-04-01 12:00:00',
                'created_at' => '2026-04-01 12:00:00',
            ]);
            Deal::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $vipClient->id,
                'product_id' => $vip->id,
                'plan_type' => 'vip',
                'status' => 'expired',
                'activated_at' => '2026-06-01 12:00:00',
                'created_at' => '2026-06-01 12:00:00',
            ]);
            Deal::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $vipClient->id,
                'product_id' => $vvip->id,
                'plan_type' => 'vip',
                'status' => 'expired',
                'activated_at' => '2026-06-12 12:00:00',
                'created_at' => '2026-06-12 12:00:00',
            ]);
            Deal::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $basicClient->id,
                'product_id' => $basic->id,
                'plan_type' => 'basic',
                'status' => 'expired',
                'activated_at' => '2026-06-01 12:00:00',
                'created_at' => '2026-06-01 12:00:00',
            ]);
            Deal::factory()->create([
                'platform_id' => $platform->id,
                'client_id' => $vvipClient->id,
                'product_id' => $vvip->id,
                'plan_type' => 'vip',
                'status' => 'expired',
                'activated_at' => '2026-06-02 12:00:00',
                'created_at' => '2026-06-02 12:00:00',
            ]);

            Client::factory()->count(9)->sequence(
                fn ($sequence) => [
                    'platform_id' => $platform->id,
                    'name' => sprintf('Queue Filler %02d', $sequence->index + 1),
                    'profile_status' => 'private',
                    'churned_at' => '2026-06-08 18:00:00',
                    'churn_reason_code' => 'expired_unrenewed',
                    'churn_source' => 'profile_inactive',
                ],
            )->create();

            Sanctum::actingAs($admin);

            $pageOne = $this->getJson('/api/crm/clients/churned?week=month&sort_by=name&sort_direction=asc&per_page=10');
            $pageOne->assertOk()
                ->assertJsonPath('total', 12)
                ->assertJsonPath('per_page', 10)
                ->assertJsonPath('last_page', 2)
                ->assertJsonPath('data.0.id', $basicClient->id)
                ->assertJsonPath('data.0.last_plan_key', 'basic')
                ->assertJsonPath('data.0.last_plan_label', 'Basic');

            $vipResponse = $this->getJson('/api/crm/clients/churned?week=month&plan=vip&search=Zuri&source=deal_expired');
            $vipResponse->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.id', $vipClient->id)
                ->assertJsonPath('data.0.last_plan_key', 'vip')
                ->assertJsonPath('data.0.last_plan_label', 'VIP');

            $signupSourceResponse = $this->getJson('/api/crm/clients/churned?week=month&signup_source=fast_signup');
            $signupSourceResponse->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.id', $basicClient->id);

            $vvipResponse = $this->getJson('/api/crm/clients/churned?week=month&plan=vvip');
            $vvipResponse->assertOk()
                ->assertJsonPath('total', 1)
                ->assertJsonPath('data.0.id', $vvipClient->id)
                ->assertJsonPath('data.0.last_plan_key', 'vvip');

            $lastPlanSort = $this->getJson('/api/crm/clients/churned?week=month&sort_by=last_plan&sort_direction=desc');
            $lastPlanSort->assertOk()
                ->assertJsonPath('data.0.id', $vvipClient->id)
                ->assertJsonPath('data.1.id', $vipClient->id)
                ->assertJsonPath('data.2.id', $basicClient->id);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_churned_queue_sorts_by_lifetime_value_across_pages(): void
    {
        Carbon::setTestNow('2026-06-11 12:00:00');

        try {
            $admin = User::factory()->create([
                'role' => 'admin',
                'status' => 'active',
                'assigned_market_ids' => [],
            ]);
            $platform = Platform::factory()->create(['currency_code' => 'USD']);
            $highValue = Client::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'High Value',
                'profile_status' => 'private',
                'churned_at' => '2026-06-10 18:00:00',
            ]);
            $midValue = Client::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'Mid Value',
                'profile_status' => 'private',
                'churned_at' => '2026-06-09 18:00:00',
            ]);
            $noValue = Client::factory()->create([
                'platform_id' => $platform->id,
                'name' => 'No Value',
                'profile_status' => 'private',
                'churned_at' => '2026-06-08 18:00:00',
            ]);
            Client::factory()->count(8)->sequence(
                fn ($sequence) => [
                    'platform_id' => $platform->id,
                    'name' => sprintf('No Value Filler %02d', $sequence->index + 1),
                    'profile_status' => 'private',
                    'churned_at' => '2026-06-07 18:00:00',
                ],
            )->create();

            Payment::factory()->create([
                'platform_id' => $platform->id,
                'product_id' => null,
                'client_id' => $midValue->id,
                'amount' => 20,
                'currency' => 'USD',
                'completed_at' => '2026-06-01 10:00:00',
                'created_at' => '2026-06-01 09:00:00',
            ]);
            Payment::factory()->create([
                'platform_id' => $platform->id,
                'product_id' => null,
                'client_id' => $highValue->id,
                'amount' => 75,
                'currency' => 'USD',
                'completed_at' => '2026-06-01 10:00:00',
                'created_at' => '2026-06-01 09:00:00',
            ]);

            Sanctum::actingAs($admin);

            $pageOne = $this->getJson('/api/crm/clients/churned?week=month&sort_by=value&sort_direction=desc&per_page=10');
            $pageOne->assertOk()
                ->assertJsonPath('total', 11)
                ->assertJsonPath('per_page', 10)
                ->assertJsonPath('last_page', 2)
                ->assertJsonPath('meta.effective_sort_by', 'value')
                ->assertJsonPath('meta.value_ranking_unavailable', false)
                ->assertJsonPath('data.0.id', $highValue->id)
                ->assertJsonPath('data.1.id', $midValue->id);
            $this->assertEqualsWithDelta(75.0, (float) $pageOne->json('data.0.lifetime_value_usd'), 0.001);
            $this->assertEqualsWithDelta(20.0, (float) $pageOne->json('data.1.lifetime_value_usd'), 0.001);

            $pageTwo = $this->getJson('/api/crm/clients/churned?week=month&sort_by=value&sort_direction=desc&per_page=10&page=2');
            $pageTwo->assertOk()
                ->assertJsonPath('current_page', 2)
                ->assertJsonPath('data.0.lifetime_payment_count', 0);
            $this->assertEqualsWithDelta(0.0, (float) $pageTwo->json('data.0.lifetime_value_usd'), 0.001);
        } finally {
            Carbon::setTestNow();
        }
    }
}
