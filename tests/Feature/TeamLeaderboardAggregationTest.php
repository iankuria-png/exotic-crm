<?php

namespace Tests\Feature;

use App\Models\Lead;
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
        $admin = $this->createTeamUser('admin');
        $platformKes = $this->createTeamPlatform(['name' => 'Kenya', 'currency_code' => 'KES']);
        $platformTzs = $this->createTeamPlatform(['name' => 'Tanzania', 'currency_code' => 'TZS', 'domain' => 'tz.test']);
        $agent = $this->createTeamUser('sales', [$platformKes->id, $platformTzs->id]);

        $lead = $this->createTeamLead($platformKes, $agent, [
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
        ]);

        $this->createTeamDeal($platformKes, $agent, [
            'amount' => 5000,
            'currency' => 'KES',
            'status' => 'expired',
            'activated_at' => now()->subHour(),
        ]);
        $this->createTeamDeal($platformTzs, $agent, [
            'amount' => 8000,
            'currency' => 'TZS',
            'status' => 'cancelled',
            'activated_at' => now()->subMinutes(30),
        ]);

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

        $response = $this->getJson('/api/crm/team/leaderboard?period=today');

        $response->assertOk()
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
}
