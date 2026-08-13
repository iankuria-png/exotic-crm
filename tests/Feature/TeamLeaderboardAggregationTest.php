<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Product;
use App\Models\ReportingFxRate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTeamActivityFixtures;
use Tests\TestCase;

class TeamLeaderboardAggregationTest extends TestCase
{
    use InteractsWithTeamActivityFixtures;
    use RefreshDatabase;

    public function test_today_leaderboard_returns_one_row_per_agent_and_preserves_currency_breakdown(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platformKes = $this->createTeamPlatform(['name' => 'Kenya', 'currency_code' => 'KES']);
        $platformTzs = $this->createTeamPlatform(['name' => 'Tanzania', 'currency_code' => 'TZS', 'domain' => 'tz.test']);
        $agent = $this->createTeamUser('sales', [$platformKes->id, $platformTzs->id]);

        $lead = $this->createTeamLead($platformKes, $agent, [
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $dealKes = $this->createTeamDeal($platformKes, $agent, [
            'amount' => 5000,
            'currency' => 'KES',
            'status' => 'expired',
            'activated_at' => now()->subHour(),
        ]);
        $dealTzs = $this->createTeamDeal($platformTzs, $agent, [
            'amount' => 8000,
            'currency' => 'TZS',
            'status' => 'cancelled',
            'activated_at' => now()->subMinutes(30),
        ]);
        $this->createTeamPayment($platformKes, $dealKes, [
            'amount' => 5000,
            'currency' => 'KES',
            'created_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
        ]);
        $this->createTeamPayment($platformTzs, $dealTzs, [
            'amount' => 8000,
            'currency' => 'TZS',
            'created_at' => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(30),
        ]);
        $this->createRate('KES', 'USD', now(), 0.0077);
        $this->createRate('TZS', 'USD', now(), 0.00038);

        $this->createTeamAudit([
            'platform_id' => $platformKes->id,
            'actor_id' => $agent->id,
            'action' => 'deal_activate',
            'entity_type' => 'deal',
            'entity_id' => 101,
            'after_state' => ['deal_status' => 'active'],
            'created_at' => now()->subHour(),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platformTzs->id,
            'actor_id' => $agent->id,
            'action' => 'deal_activate',
            'entity_type' => 'deal',
            'entity_id' => 102,
            'after_state' => ['deal_status' => 'active'],
            'created_at' => now()->subMinutes(30),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platformKes->id,
            'actor_id' => $agent->id,
            'action' => 'deal_activate',
            'entity_type' => 'deal',
            'entity_id' => 103,
            'after_state' => ['deal_status' => 'awaiting_payment'],
            'created_at' => now()->subMinutes(20),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platformKes->id,
            'actor_id' => $agent->id,
            'action' => 'payment_match_auto',
            'entity_type' => 'payment',
            'entity_id' => 201,
            'created_at' => now()->subMinutes(19),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platformKes->id,
            'actor_id' => $agent->id,
            'action' => 'payment_match_batch',
            'entity_type' => 'user',
            'entity_id' => $agent->id,
            'created_at' => now()->subMinutes(18),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platformTzs->id,
            'actor_id' => $agent->id,
            'action' => 'support_chat_reply',
            'entity_type' => 'client',
            'entity_id' => 301,
            'created_at' => now()->subMinutes(17),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platformKes->id,
            'actor_id' => $agent->id,
            'action' => 'conversation_sms_sent',
            'entity_type' => 'client',
            'entity_id' => 302,
            'created_at' => now()->subMinutes(16),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platformKes->id,
            'actor_id' => $agent->id,
            'action' => 'lead_status_update',
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'after_state' => ['status' => 'contacted'],
            'created_at' => now()->subMinutes(15),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/leaderboard?period=today&currency_mode=flat&reporting_currency=USD');

        $response->assertOk()
            ->assertJsonPath('currency_mode', 'flat')
            ->assertJsonPath('reporting_currency', 'USD')
            ->assertJsonPath('data.0.user_id', $agent->id)
            ->assertJsonPath('data.0.subs_activated', 2)
            ->assertJsonPath('data.0.payments_matched', 1)
            ->assertJsonPath('data.0.chats_replied', 1)
            ->assertJsonPath('data.0.sms_sent', 1)
            ->assertJsonPath('data.0.leads_contacted', 1)
            ->assertJsonPath('data.0.total_actions', 6);

        $this->assertCount(2, $response->json('data.0.revenue_by_currency'));
        $this->assertStringContainsString('KES 5,000', (string) $response->json('data.0.revenue_display'));
        $this->assertStringContainsString('TZS 8,000', (string) $response->json('data.0.revenue_display'));
        $this->assertSame(41.54, (float) $response->json('data.0.normalized_revenue_total'));
        $this->assertSame(false, $response->json('data.0.revenue_normalization_meta.partial'));

        Carbon::setTestNow();
    }

    public function test_week_leaderboard_combines_historical_daily_stats_with_live_today_activity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-25 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform(['currency_code' => 'KES']);
        $agent = $this->createTeamUser('sales', [$platform->id]);

        $this->createTeamDailyStat($agent, $platform, now()->startOfWeek(), [
            'subs_activated' => 3,
            'payments_matched' => 2,
            'total_actions' => 5,
        ]);
        $this->createTeamDailyStat($agent, $platform, now()->startOfWeek()->addDay(), [
            'sms_sent' => 4,
            'total_actions' => 4,
        ]);

        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'client_credential_send',
            'entity_type' => 'client',
            'entity_id' => 400,
            'created_at' => now()->subMinutes(10),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'client_credential_reset',
            'entity_type' => 'client',
            'entity_id' => 401,
            'created_at' => now()->subMinutes(9),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'client_login_as_client_link',
            'entity_type' => 'client',
            'entity_id' => 402,
            'created_at' => now()->subMinutes(8),
        ]);

        $this->createTeamSession($agent, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', [
            'started_at' => now()->subHours(2),
            'last_heartbeat_at' => now()->subMinutes(1),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/leaderboard?period=week&platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('data.0.user_id', $agent->id)
            ->assertJsonPath('data.0.subs_activated', 3)
            ->assertJsonPath('data.0.payments_matched', 2)
            ->assertJsonPath('data.0.sms_sent', 4)
            ->assertJsonPath('data.0.credentials_sent', 1)
            ->assertJsonPath('data.0.total_actions', 10);

        $this->assertGreaterThan(0, (int) $response->json('data.0.active_seconds'));

        Carbon::setTestNow();
    }

    public function test_admin_leaderboard_includes_manager_rows_and_honors_role_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:00'));

        $adminViewer = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform(['name' => 'Kenya', 'currency_code' => 'KES']);
        $peerAdmin = $this->createTeamUser('admin', [$platform->id], ['email' => 'peer-admin@example.test']);
        $subAdmin = $this->createTeamUser('sub_admin', [$platform->id], ['email' => 'peer-subadmin@example.test']);
        $sales = $this->createTeamUser('sales', [$platform->id], ['email' => 'peer-sales@example.test']);
        $marketing = $this->createTeamUser('marketing', [$platform->id], ['email' => 'peer-marketing@example.test']);

        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $peerAdmin->id,
            'action' => 'client_create',
            'entity_type' => 'client',
            'entity_id' => 901,
            'created_at' => now()->subMinutes(40),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $subAdmin->id,
            'action' => 'payment_match_auto',
            'entity_type' => 'payment',
            'entity_id' => 902,
            'created_at' => now()->subMinutes(30),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $sales->id,
            'action' => 'deal_activate',
            'entity_type' => 'deal',
            'entity_id' => 903,
            'after_state' => ['deal_status' => 'active'],
            'created_at' => now()->subMinutes(20),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $marketing->id,
            'action' => 'support_chat_reply',
            'entity_type' => 'client',
            'entity_id' => 904,
            'created_at' => now()->subMinutes(10),
        ]);

        Sanctum::actingAs($adminViewer);

        $response = $this->getJson('/api/crm/team/leaderboard?period=today&platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('role_filter', 'all');

        $allIds = collect($response->json('data'))->pluck('user_id')->sort()->values()->all();
        $this->assertSame([$peerAdmin->id, $subAdmin->id, $sales->id, $marketing->id], $allIds);

        $this->getJson('/api/crm/team/leaderboard?period=today&platform_id=' . $platform->id . '&role_filter=admin')
            ->assertOk()
            ->assertJsonPath('role_filter', 'admin')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $peerAdmin->id);

        $this->getJson('/api/crm/team/leaderboard?period=today&platform_id=' . $platform->id . '&role_filter=sub_admin')
            ->assertOk()
            ->assertJsonPath('role_filter', 'sub_admin')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $subAdmin->id);

        $this->getJson('/api/crm/team/leaderboard?period=today&platform_id=' . $platform->id . '&role_filter=sales')
            ->assertOk()
            ->assertJsonPath('role_filter', 'sales')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $sales->id);

        $this->getJson('/api/crm/team/leaderboard?period=today&platform_id=' . $platform->id . '&role_filter=marketing')
            ->assertOk()
            ->assertJsonPath('role_filter', 'marketing')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $marketing->id);

        Carbon::setTestNow();
    }

    public function test_flat_leaderboard_ranks_by_collected_payment_revenue_not_activated_deals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-20 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platformKes = $this->createTeamPlatform(['name' => 'Kenya', 'currency_code' => 'KES']);
        $platformGhs = $this->createTeamPlatform(['name' => 'Ghana', 'currency_code' => 'GHS', 'domain' => 'gh.test']);
        $agentA = $this->createTeamUser('sales', [$platformKes->id], ['name' => 'Agent A', 'email' => 'agent-a@example.test']);
        $agentB = $this->createTeamUser('sales', [$platformGhs->id], ['name' => 'Agent B', 'email' => 'agent-b@example.test']);

        $unpaidHighValueDeal = $this->createTeamDeal($platformKes, $agentA, [
            'amount' => 500000,
            'currency' => 'KES',
            'activated_at' => now()->subHour(),
        ]);
        $paidDeal = $this->createTeamDeal($platformGhs, $agentB, [
            'amount' => 200,
            'currency' => 'GHS',
            'activated_at' => now()->subHour(),
        ]);
        $this->createTeamPayment($platformGhs, $paidDeal, [
            'amount' => 200,
            'currency' => 'GHS',
            'created_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
        ]);
        $this->createRate('GHS', 'USD', now(), 0.085);
        $this->createRate('KES', 'USD', now(), 0.0077);

        $this->createTeamAudit([
            'platform_id' => $platformKes->id,
            'actor_id' => $agentA->id,
            'action' => 'deal_activate',
            'entity_type' => 'deal',
            'entity_id' => $unpaidHighValueDeal->id,
            'after_state' => ['deal_status' => 'active'],
            'created_at' => now()->subHour(),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platformGhs->id,
            'actor_id' => $agentB->id,
            'action' => 'payment_match_auto',
            'entity_type' => 'payment',
            'entity_id' => $paidDeal->id,
            'created_at' => now()->subMinutes(30),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/leaderboard?period=today&currency_mode=flat&reporting_currency=USD');

        $response->assertOk()
            ->assertJsonPath('data.0.user_id', $agentB->id)
            ->assertJsonPath('data.0.normalized_revenue_total', 17)
            ->assertJsonPath('data.1.user_id', $agentA->id)
            ->assertJsonPath('data.1.normalized_revenue_total', 0);

        Carbon::setTestNow();
    }

    public function test_leaderboard_revenue_window_uses_payment_completed_at_when_available(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform(['name' => 'Kenya', 'currency_code' => 'KES']);
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'completed-window@example.test']);
        $deal = $this->createTeamDeal($platform, $agent, [
            'amount' => 10000,
            'currency' => 'KES',
        ]);

        $this->createTeamPayment($platform, $deal, [
            'amount' => 10000,
            'currency' => 'KES',
            'created_at' => now()->subDay(),
            'completed_at' => now()->subHour(),
        ]);
        $this->createRate('KES', 'USD', now(), 0.0077);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/leaderboard?period=today&platform_id=' . $platform->id . '&currency_mode=flat&reporting_currency=USD');

        $response->assertOk()
            ->assertJsonPath('data.0.user_id', $agent->id)
            ->assertJsonPath('data.0.revenue_display', 'KES 10,000')
            ->assertJsonPath('data.0.normalized_revenue_total', 77);

        Carbon::setTestNow();
    }

    public function test_leaderboard_honors_custom_date_ranges_for_ceo_dashboard_alignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-04 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform(['name' => 'Kenya', 'currency_code' => 'KES']);
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'custom-window@example.test']);
        $deal = $this->createTeamDeal($platform, $agent, [
            'amount' => 100000,
            'currency' => 'KES',
        ]);

        $this->createTeamPayment($platform, $deal, [
            'amount' => 100000,
            'currency' => 'KES',
            'created_at' => Carbon::parse('2026-05-20 09:00:00'),
            'completed_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);
        $this->createTeamPayment($platform, $deal, [
            'amount' => 50000,
            'currency' => 'KES',
            'created_at' => Carbon::parse('2026-04-28 09:00:00'),
            'completed_at' => Carbon::parse('2026-04-28 09:00:00'),
        ]);
        $this->createRate('KES', 'USD', Carbon::parse('2026-05-20'), 0.0077);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/leaderboard?from=2026-05-06&to=2026-06-04&platform_id=' . $platform->id . '&currency_mode=flat&reporting_currency=USD');

        $response->assertOk()
            ->assertJsonPath('from', '2026-05-06')
            ->assertJsonPath('to', '2026-06-04')
            ->assertJsonPath('data.0.user_id', $agent->id)
            ->assertJsonPath('data.0.revenue_display', 'KES 100,000')
            ->assertJsonPath('data.0.normalized_revenue_total', 770);

        Carbon::setTestNow();
    }

    public function test_agent_stats_include_platform_and_package_revenue_contribution(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platformKes = $this->createTeamPlatform([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'KES',
        ]);
        $platformTzs = $this->createTeamPlatform([
            'name' => 'Tanzania',
            'country' => 'Tanzania',
            'currency_code' => 'TZS',
            'domain' => 'tz-contribution.test',
        ]);
        $agent = $this->createTeamUser('sales', [$platformKes->id, $platformTzs->id], [
            'name' => 'Contribution Agent',
            'email' => 'contribution-agent@example.test',
        ]);

        $vipKes = Product::factory()->create([
            'platform_id' => $platformKes->id,
            'currency' => 'KES',
            'tier' => 'vip',
            'name' => 'VIP',
            'display_name' => 'VIP',
            'slug' => 'vip',
        ]);
        $premiumKes = Product::factory()->create([
            'platform_id' => $platformKes->id,
            'currency' => 'KES',
            'tier' => 'premium',
            'name' => 'Premium',
            'display_name' => 'Premium',
            'slug' => 'premium',
        ]);
        $vipTzs = Product::factory()->create([
            'platform_id' => $platformTzs->id,
            'currency' => 'TZS',
            'tier' => 'vip',
            'name' => 'VIP',
            'display_name' => 'VIP',
            'slug' => 'vip',
        ]);

        $dealVipKes = $this->createTeamDeal($platformKes, $agent, [
            'product' => $vipKes,
            'amount' => 10000,
            'currency' => 'KES',
        ]);
        $dealPremiumKes = $this->createTeamDeal($platformKes, $agent, [
            'product' => $premiumKes,
            'amount' => 5000,
            'currency' => 'KES',
        ]);
        $dealVipTzs = $this->createTeamDeal($platformTzs, $agent, [
            'product' => $vipTzs,
            'amount' => 200000,
            'currency' => 'TZS',
        ]);

        $this->createTeamPayment($platformKes, $dealVipKes, [
            'amount' => 10000,
            'currency' => 'KES',
            'created_at' => now()->subHours(3),
            'completed_at' => now()->subHours(3),
        ]);
        $this->createTeamPayment($platformKes, $dealPremiumKes, [
            'amount' => 5000,
            'currency' => 'KES',
            'created_at' => now()->subHours(2),
            'completed_at' => now()->subHours(2),
        ]);
        $this->createTeamPayment($platformTzs, $dealVipTzs, [
            'amount' => 200000,
            'currency' => 'TZS',
            'created_at' => now()->subHour(),
            'completed_at' => now()->subHour(),
        ]);
        $this->createRate('KES', 'USD', now(), 0.01);
        $this->createRate('TZS', 'USD', now(), 0.0005);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/' . $agent->id . '/stats?from=2026-06-02&to=2026-06-02&reporting_currency=USD');

        $response->assertOk()
            ->assertJsonPath('contribution.target_currency', 'USD')
            ->assertJsonPath('contribution.summary.platform_count', 2)
            ->assertJsonPath('contribution.summary.package_count', 2)
            ->assertJsonPath('contribution.platforms.0.name', 'Kenya')
            ->assertJsonPath('contribution.platforms.1.name', 'Tanzania')
            ->assertJsonPath('contribution.packages.0.label', 'VIP')
            ->assertJsonPath('contribution.packages.1.label', 'Premium');

        $this->assertEqualsWithDelta(250.0, (float) $response->json('contribution.total_normalized'), 0.01);
        $this->assertEqualsWithDelta(60.0, (float) $response->json('contribution.platforms.0.share_percent'), 0.01);
        $this->assertEqualsWithDelta(40.0, (float) $response->json('contribution.platforms.1.share_percent'), 0.01);
        $this->assertEqualsWithDelta(80.0, (float) $response->json('contribution.packages.0.share_percent'), 0.01);
        $this->assertEqualsWithDelta(20.0, (float) $response->json('contribution.packages.1.share_percent'), 0.01);
        $this->assertCount(2, $response->json('contribution.packages.0.platforms'));

        Carbon::setTestNow();
    }

    public function test_agent_stats_answer_client_category_recovery_winback_and_workload_questions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-02 12:00:00'));

        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'KES',
        ]);
        $agent = $this->createTeamUser('sales', [$platform->id], [
            'name' => 'Client Portfolio Agent',
            'email' => 'client-portfolio-agent@example.test',
        ]);
        $busyPeer = $this->createTeamUser('sales', [$platform->id], [
            'name' => 'Busier Peer',
            'email' => 'busier-peer@example.test',
        ]);
        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'currency' => 'KES',
            'tier' => 'vip',
            'name' => 'VIP',
            'display_name' => 'VIP',
            'slug' => 'vip',
        ]);

        $newClient = $this->createTeamClient($platform, [
            'assigned_to' => $agent->id,
            'created_at' => now()->subHours(5),
            'first_activated_at' => now()->subHours(4),
        ]);
        $existingClient = $this->createTeamClient($platform, [
            'assigned_to' => $agent->id,
            'created_at' => now()->subMonth(),
            'first_activated_at' => now()->subMonth()->addDay(),
        ]);
        $recoveryClient = $this->createTeamClient($platform, [
            'assigned_to' => $agent->id,
            'created_at' => now()->subMonth(),
            'first_activated_at' => now()->subMonth()->addDays(2),
        ]);
        $winbackClient = $this->createTeamClient($platform, [
            'assigned_to' => $agent->id,
            'created_at' => now()->subMonths(2),
            'first_activated_at' => now()->subMonths(2)->addDay(),
            'churned_at' => now()->subWeek(),
        ]);

        $newDeal = $this->createTeamDeal($platform, $agent, ['client' => $newClient, 'product' => $product, 'amount' => 10000]);
        $existingDeal = $this->createTeamDeal($platform, $agent, ['client' => $existingClient, 'product' => $product, 'amount' => 5000]);
        $recoveryDeal = $this->createTeamDeal($platform, $agent, ['client' => $recoveryClient, 'product' => $product, 'amount' => 7000]);
        $winbackDeal = $this->createTeamDeal($platform, $agent, [
            'client' => $winbackClient,
            'product' => $product,
            'amount' => 8000,
            'subscription_lifecycle' => 'reactivation',
        ]);

        $this->createTeamPayment($platform, $newDeal, ['amount' => 10000, 'currency' => 'KES', 'created_at' => now()->subHours(4), 'completed_at' => now()->subHours(4)]);
        $this->createTeamPayment($platform, $existingDeal, ['amount' => 5000, 'currency' => 'KES', 'created_at' => now()->subHours(3), 'completed_at' => now()->subHours(3)]);
        $this->createTeamPayment($platform, $recoveryDeal, ['amount' => 7000, 'currency' => 'KES', 'status' => 'failed', 'created_at' => now()->subHours(2)]);
        $this->createTeamPayment($platform, $recoveryDeal, ['amount' => 7000, 'currency' => 'KES', 'created_at' => now()->subHour(), 'completed_at' => now()->subHour()]);
        $this->createTeamPayment($platform, $winbackDeal, [
            'amount' => 8000,
            'currency' => 'KES',
            'subscription_lifecycle' => 'reactivation',
            'created_at' => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(30),
        ]);
        $this->createRate('KES', 'USD', now(), 0.01);

        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'deal_activate',
            'entity_type' => 'deal',
            'entity_id' => $newDeal->id,
            'after_state' => ['deal_status' => 'active'],
            'created_at' => now()->subHour(),
        ]);
        foreach (range(1, 4) as $index) {
            $this->createTeamAudit([
                'platform_id' => $platform->id,
                'actor_id' => $busyPeer->id,
                'action' => 'client_create',
                'entity_type' => 'client',
                'entity_id' => 900 + $index,
                'created_at' => now()->subMinutes($index),
            ]);
        }

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/' . $agent->id . '/stats?from=2026-06-02&to=2026-06-02&platform_id=' . $platform->id . '&reporting_currency=USD');

        $response->assertOk()
            ->assertJsonPath('client_performance.customer_mix.0.key', 'new_users')
            ->assertJsonPath('client_performance.customer_mix.1.key', 'existing_users')
            ->assertJsonPath('client_performance.conversion.new_clients', 1)
            ->assertJsonPath('client_performance.conversion.converted_clients', 1)
            ->assertJsonPath('client_performance.payment_recovery.failed_payments', 1)
            ->assertJsonPath('client_performance.payment_recovery.failed_clients', 1)
            ->assertJsonPath('client_performance.payment_recovery.recovered_clients', 1)
            ->assertJsonPath('client_performance.winback.lost_clients_at_start', 1)
            ->assertJsonPath('client_performance.winback.won_back_clients', 1)
            ->assertJsonPath('client_performance.workload.rank', 2)
            ->assertJsonPath('client_performance.workload.team_size', 2)
            ->assertJsonPath('client_performance.workload.band', 'Least busy visible team member');

        $this->assertEqualsWithDelta(300.0, (float) $response->json('client_performance.total_normalized'), 0.01);
        $this->assertEqualsWithDelta(33.3, (float) $response->json('client_performance.customer_mix.0.share_percent'), 0.1);
        $this->assertEqualsWithDelta(66.7, (float) $response->json('client_performance.customer_mix.1.share_percent'), 0.1);
        $this->assertEqualsWithDelta(100.0, (float) $response->json('client_performance.conversion.rate'), 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $response->json('client_performance.payment_recovery.rate'), 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $response->json('client_performance.winback.rate'), 0.01);

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
