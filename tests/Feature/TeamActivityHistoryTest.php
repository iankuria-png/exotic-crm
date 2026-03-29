<?php

namespace Tests\Feature;

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
}
