<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Services\ChurnAggregatorService;
use App\Services\ClientChurnStamper;
use App\Support\CrmClientChurnReason;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->assertSame(45.0, $summary['daily'][0]['estimated_revenue_at_risk_usd']);
        $this->assertSame(45.0, $summary['revenue_at_risk']['estimated_total']);
        $this->assertSame(100.0, $summary['revenue_at_risk']['coverage_percent']);
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
        $this->assertNull($summary['daily'][0]['estimated_revenue_at_risk_usd']);
        $this->assertSame(0, $summary['revenue_at_risk']['covered_churn_count']);
        $this->assertSame(0.0, $summary['revenue_at_risk']['coverage_percent']);
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
}
