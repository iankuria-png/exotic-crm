<?php

namespace Tests\Feature;

use App\Models\ReportingFxRate;
use App\Support\CrmAuditAction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTeamActivityFixtures;
use Tests\TestCase;

class TeamActivityHistoryTest extends TestCase
{
    use InteractsWithTeamActivityFixtures;
    use RefreshDatabase;

    public function test_agent_activity_feed_can_return_the_selected_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-26 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform(['name' => 'Kenya']);
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'history-agent@example.test']);

        $yesterdayLog = $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'conversation_sms_sent',
            'entity_type' => 'client',
            'entity_id' => 201,
            'created_at' => now()->subDay()->setTime(14, 0),
        ]);
        $todayLog = $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'payment_match_auto',
            'entity_type' => 'payment',
            'entity_id' => 202,
            'created_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/' . $agent->id . '/activity?from=2026-03-25&to=2026-03-26&platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('from', '2026-03-25')
            ->assertJsonPath('to', '2026-03-26');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$todayLog->id, $yesterdayLog->id], $ids);

        Carbon::setTestNow();
    }

    public function test_agent_activity_feed_still_supports_single_day_date_queries(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-26 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform(['name' => 'Kenya']);
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'day-agent@example.test']);

        $todayLog = $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'payment_match_auto',
            'entity_type' => 'payment',
            'entity_id' => 301,
            'created_at' => now()->subHour(),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'conversation_sms_sent',
            'entity_type' => 'client',
            'entity_id' => 302,
            'created_at' => now()->subDay()->setTime(10, 30),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/' . $agent->id . '/activity?date=2026-03-26&platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('from', '2026-03-26')
            ->assertJsonPath('to', '2026-03-26')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $todayLog->id);

        Carbon::setTestNow();
    }

    public function test_agent_activity_feed_paginates_excludes_system_events_and_enriches_payment_and_deal_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:00'));

        $admin = $this->createTeamUser('admin', [], ['name' => 'Manager One']);
        $platform = $this->createTeamPlatform(['name' => 'Kenya', 'currency_code' => 'KES']);
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'activity-enriched@example.test']);
        $client = $this->createTeamClient($platform, ['name' => 'Ada Client', 'assigned_to' => $agent->id]);
        $deal = $this->createTeamDeal($platform, $agent, [
            'client' => $client,
            'amount' => 15000,
            'original_amount' => 20000,
            'discount_percentage' => 25,
            'discount_approved_by' => $admin->id,
            'discount_source' => 'manager_override',
        ]);
        $payment = $this->createTeamPayment($platform, $deal, [
            'amount' => 12900,
            'currency' => 'KES',
            'provider_key' => 'pawa_pay',
            'source' => 'hosted_checkout',
            'created_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(35),
        ]);
        $this->createRate('KES', 'USD', now(), 0.01);

        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => CrmAuditAction::WHATSAPP_DELIVERED,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'created_at' => now()->subMinutes(5),
        ]);
        $paymentLog = $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => CrmAuditAction::PAYMENT_MANUAL_APPROVE,
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
            'reason' => 'Manual review completed',
            'created_at' => now()->subMinutes(20),
        ]);
        $discountLog = $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => CrmAuditAction::DEAL_DISCOUNT,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'after_state' => [
                'discount_percentage' => 25,
                'original_amount' => 20000,
                'amount' => 15000,
                'discount_approved_by' => $admin->id,
                'discount_source' => 'manager_override',
            ],
            'created_at' => now()->subMinutes(25),
        ]);
        $trialLog = $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => CrmAuditAction::DEAL_FREE_TRIAL,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'after_state' => ['duration_days' => 7],
            'created_at' => now()->subMinutes(40),
        ]);
        foreach (range(1, 3) as $index) {
            $this->createTeamAudit([
                'platform_id' => $platform->id,
                'actor_id' => $agent->id,
                'action' => CrmAuditAction::CLIENT_CREDENTIAL_SEND,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'created_at' => now()->subMinutes(40 + $index),
            ]);
        }

        Sanctum::actingAs($admin);

        $firstPage = $this->getJson('/api/crm/team/' . $agent->id . '/activity?from=2026-06-02&to=2026-06-02&platform_id=' . $platform->id . '&per_page=5&reporting_currency=USD');

        $firstPage->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.total', 6)
            ->assertJsonPath('data.0.id', $paymentLog->id)
            ->assertJsonPath('data.0.actor.name', $agent->name)
            ->assertJsonPath('data.0.payment.client.name', 'Ada Client')
            ->assertJsonPath('data.0.payment.normalized_total', 129)
            ->assertJsonPath('data.0.payment.normalized_currency', 'USD')
            ->assertJsonPath('data.0.payment.channel.label', 'Self-service')
            ->assertJsonPath('data.0.payment.method.label', 'Pawa Pay')
            ->assertJsonPath('data.1.id', $discountLog->id)
            ->assertJsonPath('data.1.deal_meta.type', 'discount')
            ->assertJsonPath('data.1.deal_meta.client.name', 'Ada Client')
            ->assertJsonPath('data.1.deal_meta.approver.name', 'Manager One')
            ->assertJsonPath('data.1.deal_meta.discount_percentage', 25);

        $secondPage = $this->getJson('/api/crm/team/' . $agent->id . '/activity?from=2026-06-02&to=2026-06-02&platform_id=' . $platform->id . '&per_page=5&page=2&reporting_currency=USD');

        $secondPage->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonCount(1, 'data');

        $includeSystem = $this->getJson('/api/crm/team/' . $agent->id . '/activity?from=2026-06-02&to=2026-06-02&platform_id=' . $platform->id . '&include_system=1&per_page=5');

        $includeSystem->assertOk()
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('data.0.action', CrmAuditAction::WHATSAPP_DELIVERED);

        $focused = $this->getJson('/api/crm/team/' . $agent->id . '/activity?from=2026-06-02&to=2026-06-02&platform_id=' . $platform->id . '&action_focus=free_trials_discounts&per_page=10');

        $focused->assertOk();
        $this->assertSame(
            [$discountLog->id, $trialLog->id],
            collect($focused->json('data'))->pluck('id')->all()
        );

        Carbon::setTestNow();
    }

    public function test_payment_filter_returns_collected_payment_records_assigned_to_the_agent(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform(['name' => 'Kenya', 'currency_code' => 'KES']);
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'payment-filter@example.test']);
        $deal = $this->createTeamDeal($platform, $agent, [
            'amount' => 15400,
            'currency' => 'KES',
            'expires_at' => now()->addMonth(),
        ]);
        $payment = $this->createTeamPayment($platform, $deal, [
            'amount' => 15400,
            'currency' => 'KES',
            'transaction_reference' => 'PAY-15400',
            'provider_key' => 'mpesa_stk',
            'source' => 'hosted_checkout',
            'created_at' => now()->subHour(),
            'completed_at' => now()->subMinutes(20),
        ]);
        $this->createRate('KES', 'USD', now(), 0.0077);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/' . $agent->id . '/activity?from=2026-06-04&to=2026-06-04&platform_id=' . $platform->id . '&entity_type=payment&reporting_currency=USD');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $payment->id)
            ->assertJsonPath('data.0.action', 'payment_record')
            ->assertJsonPath('data.0.payment.normalized_total', 118.58)
            ->assertJsonPath('data.0.payment.channel.label', 'Self-service')
            ->assertJsonPath('data.0.deal_meta.amount_display', 'KES 15,400')
            ->assertJsonPath('data.0.deal_meta.product.name', $deal->product->display_name ?: $deal->product->name);

        Carbon::setTestNow();
    }

    public function test_subscription_filter_includes_value_product_status_and_expiry_context(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform(['name' => 'Ghana', 'currency_code' => 'GHS']);
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'subscription-filter@example.test']);
        $deal = $this->createTeamDeal($platform, $agent, [
            'amount' => 350,
            'currency' => 'GHS',
            'plan_type' => 'vip',
            'duration' => 'monthly',
            'status' => 'active',
            'expires_at' => Carbon::parse('2026-07-04 12:00:00'),
        ]);
        $auditLog = $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => CrmAuditAction::DEAL_ACTIVATE,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'after_state' => ['deal_status' => 'active'],
            'created_at' => now()->subMinutes(15),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/' . $agent->id . '/activity?from=2026-06-04&to=2026-06-04&platform_id=' . $platform->id . '&entity_type=subscription');

        $response->assertOk()
            ->assertJsonPath('data.0.id', $auditLog->id)
            ->assertJsonPath('data.0.entity_type', 'deal')
            ->assertJsonPath('data.0.deal_meta.type', 'subscription')
            ->assertJsonPath('data.0.deal_meta.amount_display', 'GHS 350')
            ->assertJsonPath('data.0.deal_meta.status', 'active')
            ->assertJsonPath('data.0.deal_meta.duration', 'monthly')
            ->assertJsonPath('data.0.deal_meta.plan_type', 'vip')
            ->assertJsonPath('data.0.deal_meta.product.name', $deal->product->display_name ?: $deal->product->name)
            ->assertJsonPath('data.0.deal_meta.expires_at', '2026-07-04T12:00:00+00:00');

        Carbon::setTestNow();
    }

    public function test_team_me_platform_options_include_inactive_but_accessible_markets(): void
    {
        $subAdmin = $this->createTeamUser('sub_admin');
        $activePlatform = $this->createTeamPlatform([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'is_active' => true,
        ]);
        $inactivePlatform = $this->createTeamPlatform([
            'name' => 'Tanzania',
            'country' => 'Tanzania',
            'domain' => 'tz-market.test',
            'currency_code' => 'TZS',
            'is_active' => false,
        ]);

        $subAdmin->assigned_market_ids = [$activePlatform->id, $inactivePlatform->id];
        $subAdmin->save();
        $subAdmin->platforms()->syncWithoutDetaching([$activePlatform->id, $inactivePlatform->id]);

        Sanctum::actingAs($subAdmin);

        $response = $this->getJson('/api/crm/team/me?period=week');

        $response->assertOk();

        $platformIds = collect($response->json('platforms'))
            ->pluck('platform_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$activePlatform->id, $inactivePlatform->id], $platformIds);
    }

    public function test_my_stats_activity_list_respects_the_selected_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-26 12:00:00'));

        $platform = $this->createTeamPlatform(['name' => 'Kenya']);
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'self-history@example.test']);

        $outsideWindowLog = $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'conversation_sms_sent',
            'entity_type' => 'client',
            'entity_id' => 401,
            'created_at' => now()->subWeeks(2),
        ]);
        $insideWindowLog = $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'payment_match_auto',
            'entity_type' => 'payment',
            'entity_id' => 402,
            'created_at' => now()->subDays(2),
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/crm/team/me?period=week&platform_id=' . $platform->id);

        $response->assertOk();

        $activityIds = collect($response->json('activity'))->pluck('id')->all();

        $this->assertSame([$insideWindowLog->id], $activityIds);
        $this->assertNotContains($outsideWindowLog->id, $activityIds);

        Carbon::setTestNow();
    }

    public function test_admin_can_open_stats_and_activity_for_manager_accounts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-26 12:00:00'));

        $adminViewer = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform(['name' => 'Kenya']);
        $peerAdmin = $this->createTeamUser('admin', [], ['email' => 'stats-admin@example.test']);
        $subAdmin = $this->createTeamUser('sub_admin', [$platform->id], ['email' => 'activity-subadmin@example.test']);

        $this->createTeamDailyStat($peerAdmin, $platform, now()->startOfWeek(), [
            'payments_matched' => 2,
            'total_actions' => 2,
        ]);
        $auditLog = $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $subAdmin->id,
            'action' => 'support_chat_reply',
            'entity_type' => 'client',
            'entity_id' => 501,
            'created_at' => now()->subHour(),
        ]);

        Sanctum::actingAs($adminViewer);

        $this->getJson('/api/crm/team/' . $peerAdmin->id . '/stats?from=2026-03-23&to=2026-03-26&platform_id=' . $platform->id)
            ->assertOk()
            ->assertJsonPath('agent.id', $peerAdmin->id)
            ->assertJsonPath('summary.payments_matched', 2);

        $this->getJson('/api/crm/team/' . $subAdmin->id . '/activity?from=2026-03-26&to=2026-03-26&platform_id=' . $platform->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $auditLog->id);

        Carbon::setTestNow();
    }

    private function createRate(string $source, string $target, Carbon $date, float $rate): void
    {
        ReportingFxRate::query()->create([
            'provider' => 'currencyapi',
            'source_currency' => $source,
            'target_currency' => $target,
            'rate_date' => $date->toDateString(),
            'rate' => $rate,
            'fetched_at' => $date,
            'payload_hash' => sha1($source . $target . $date->toDateString() . $rate),
        ]);
    }
}
