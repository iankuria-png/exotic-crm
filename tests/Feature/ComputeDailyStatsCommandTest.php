<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTeamActivityFixtures;
use Tests\TestCase;

class ComputeDailyStatsCommandTest extends TestCase
{
    use InteractsWithTeamActivityFixtures;
    use RefreshDatabase;

    public function test_command_rolls_up_action_metrics_and_stable_revenue_for_the_given_day(): void
    {
        $targetDate = Carbon::parse('2026-03-20 00:00:00');
        Carbon::setTestNow('2026-03-25 10:00:00');

        $platform = $this->createTeamPlatform(['currency_code' => 'KES']);
        $agent = $this->createTeamUser('sales', [$platform->id]);
        $sessionOnlyUser = $this->createTeamUser('sales', [$platform->id], ['email' => 'idle@example.test']);
        $lead = $this->createTeamLead($platform, $agent, [
            'created_at' => $targetDate->copy()->addHours(8),
            'updated_at' => $targetDate->copy()->addHours(8),
        ]);

        $this->createTeamSession($sessionOnlyUser, 'cccccccc-cccc-cccc-cccc-cccccccccccc', [
            'started_at' => $targetDate->copy()->addHours(10),
            'last_heartbeat_at' => $targetDate->copy()->addHours(11),
        ]);

        $this->createTeamDeal($platform, $agent, [
            'amount' => 5000,
            'currency' => 'KES',
            'status' => 'expired',
            'activated_at' => $targetDate->copy()->addHours(9),
            'is_free_trial' => false,
        ]);
        $this->createTeamDeal($platform, $agent, [
            'amount' => 3000,
            'currency' => 'KES',
            'status' => 'cancelled',
            'activated_at' => $targetDate->copy()->addHours(10),
            'is_free_trial' => false,
        ]);
        $this->createTeamDeal($platform, $agent, [
            'amount' => 0,
            'currency' => 'KES',
            'status' => 'active',
            'activated_at' => $targetDate->copy()->addHours(11),
            'is_free_trial' => true,
        ]);

        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'deal_activate',
            'entity_type' => 'deal',
            'entity_id' => 501,
            'after_state' => ['deal_status' => 'active'],
            'created_at' => $targetDate->copy()->addHours(9),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'deal_activate',
            'entity_type' => 'deal',
            'entity_id' => 502,
            'after_state' => ['deal_status' => 'awaiting_payment'],
            'created_at' => $targetDate->copy()->addHours(9)->addMinutes(5),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'deal_renew',
            'entity_type' => 'deal',
            'entity_id' => 503,
            'after_state' => ['new_status' => 'active'],
            'created_at' => $targetDate->copy()->addHours(10),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'deal_free_trial',
            'entity_type' => 'deal',
            'entity_id' => 504,
            'created_at' => $targetDate->copy()->addHours(11),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'payment_match_confirm',
            'entity_type' => 'payment',
            'entity_id' => 505,
            'created_at' => $targetDate->copy()->addHours(12),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'support_chat_reply',
            'entity_type' => 'client',
            'entity_id' => 506,
            'created_at' => $targetDate->copy()->addHours(13),
        ]);
        $this->createTeamAudit([
            'platform_id' => $platform->id,
            'actor_id' => $agent->id,
            'action' => 'lead_status_update',
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'after_state' => ['status' => 'contacted'],
            'created_at' => $targetDate->copy()->addHours(14),
        ]);

        $this->artisan('crm:compute-daily-stats', [
            '--date' => $targetDate->toDateString(),
        ])->assertExitCode(0);

        $this->assertDatabaseHas('agent_daily_stats', [
            'user_id' => $agent->id,
            'platform_id' => $platform->id,
            'date' => $targetDate->toDateString(),
            'subs_activated' => 1,
            'subs_renewed' => 1,
            'payments_matched' => 1,
            'leads_contacted' => 1,
            'chats_replied' => 1,
            'free_trials_given' => 1,
            'revenue' => 8000.00,
            'revenue_currency' => 'KES',
            'total_actions' => 6,
        ]);

        $this->assertDatabaseMissing('agent_daily_stats', [
            'user_id' => $sessionOnlyUser->id,
            'platform_id' => $platform->id,
            'date' => $targetDate->toDateString(),
        ]);

        Carbon::setTestNow();
    }
}
