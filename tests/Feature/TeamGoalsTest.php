<?php

namespace Tests\Feature;

use App\Models\AgentGoal;
use App\Models\AgentGoalOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTeamActivityFixtures;
use Tests\TestCase;

class TeamGoalsTest extends TestCase
{
    use InteractsWithTeamActivityFixtures;
    use RefreshDatabase;

    public function test_admin_can_upsert_and_delete_a_sales_default_goal(): void
    {
        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform();

        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/team/goals', [
            'metric' => 'subs_activated',
            'target' => 10,
            'period' => 'weekly',
            'platform_id' => $platform->id,
            'role_scope' => 'sales',
        ])->assertCreated()
            ->assertJsonPath('goal.metric', 'subs_activated')
            ->assertJsonPath('goal.target', 10)
            ->assertJsonPath('goal.role_scope', 'sales');

        $this->assertDatabaseCount('agent_goals', 1);

        $this->postJson('/api/crm/team/goals', [
            'metric' => 'subs_activated',
            'target' => 12,
            'period' => 'weekly',
            'platform_id' => $platform->id,
            'role_scope' => 'sales',
        ])->assertCreated()
            ->assertJsonPath('goal.target', 12);

        $this->assertDatabaseCount('agent_goals', 1);

        $goal = AgentGoal::query()->firstOrFail();
        $this->assertSame(12, (int) $goal->target);

        $this->deleteJson('/api/crm/team/goals/' . $goal->id)->assertNoContent();
        $this->assertDatabaseCount('agent_goals', 0);
    }

    public function test_admin_can_upsert_and_delete_an_individual_goal_override(): void
    {
        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform();
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'override-agent@example.test']);

        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/team/goals/overrides', [
            'user_id' => $agent->id,
            'metric' => 'subs_activated',
            'target' => 5,
            'period' => 'weekly',
            'platform_id' => $platform->id,
        ])->assertCreated()
            ->assertJsonPath('goal_override.user_id', $agent->id)
            ->assertJsonPath('goal_override.target', 5);

        $this->assertDatabaseCount('agent_goal_overrides', 1);

        $this->postJson('/api/crm/team/goals/overrides', [
            'user_id' => $agent->id,
            'metric' => 'subs_activated',
            'target' => 9,
            'period' => 'weekly',
            'platform_id' => $platform->id,
        ])->assertCreated()
            ->assertJsonPath('goal_override.target', 9);

        $this->assertDatabaseCount('agent_goal_overrides', 1);

        $goalOverride = AgentGoalOverride::query()->firstOrFail();
        $this->assertSame(9, (int) $goalOverride->target);

        $this->deleteJson('/api/crm/team/goals/overrides/' . $goalOverride->id)->assertNoContent();
        $this->assertDatabaseCount('agent_goal_overrides', 0);
    }

    public function test_sales_scoped_default_goals_exclude_marketing_progress_rows(): void
    {
        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform();
        $agentA = $this->createTeamUser('sales', [$platform->id], ['email' => 'agent-a@example.test']);
        $agentB = $this->createTeamUser('marketing', [$platform->id], ['email' => 'agent-b@example.test']);

        $goal = AgentGoal::query()->create([
            'platform_id' => $platform->id,
            'metric' => 'subs_activated',
            'target' => 10,
            'period' => 'weekly',
            'role_scope' => 'sales',
            'set_by' => $admin->id,
        ]);

        $this->createTeamDailyStat($agentA, $platform, now()->startOfWeek(), [
            'subs_activated' => 7,
            'total_actions' => 7,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/goals?period=weekly&platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('defaults.0.id', $goal->id)
            ->assertJsonPath('defaults.0.metric', 'subs_activated')
            ->assertJsonPath('defaults.0.role_scope', 'sales')
            ->assertJsonPath('defaults.0.progress.0.current', 7)
            ->assertJsonPath('defaults.0.progress.0.percentage', 70);

        $progressNames = collect($response->json('defaults.0.progress'))->pluck('name')->all();
        $this->assertSame([$agentA->name], $progressNames);
    }

    public function test_my_stats_prefers_individual_goal_override_over_market_default(): void
    {
        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform();
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'agent-self@example.test']);

        AgentGoal::query()->create([
            'platform_id' => $platform->id,
            'metric' => 'subs_activated',
            'target' => 10,
            'period' => 'weekly',
            'role_scope' => 'sales',
            'set_by' => $admin->id,
        ]);

        AgentGoalOverride::query()->create([
            'user_id' => $agent->id,
            'platform_id' => $platform->id,
            'metric' => 'subs_activated',
            'target' => 15,
            'period' => 'weekly',
            'set_by' => $admin->id,
        ]);

        $this->createTeamDailyStat($agent, $platform, now()->startOfWeek(), [
            'subs_activated' => 3,
            'total_actions' => 3,
        ]);

        Sanctum::actingAs($agent);

        $response = $this->getJson('/api/crm/team/me?period=week&platform_id=' . $platform->id);

        $response->assertOk();

        $goal = collect($response->json('goals'))
            ->firstWhere('metric', 'subs_activated');

        $this->assertNotNull($goal);
        $this->assertSame('override', $goal['source_type']);
        $this->assertSame(15, $goal['target']);
        $this->assertSame(3, $goal['current']);
    }

    public function test_revenue_goal_tracks_normalized_payment_progress(): void
    {
        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform([
            'currency_code' => 'USD',
        ]);
        $agent = $this->createTeamUser('sales', [$platform->id], ['email' => 'revenue-goal-agent@example.test']);
        $deal = $this->createTeamDeal($platform, $agent, [
            'amount' => 2500,
            'currency' => 'USD',
        ]);

        AgentGoal::query()->create([
            'platform_id' => $platform->id,
            'metric' => 'revenue',
            'target' => 10000,
            'target_currency' => 'USD',
            'period' => 'weekly',
            'role_scope' => 'sales',
            'set_by' => $admin->id,
        ]);

        $this->createTeamPayment($platform, $deal, [
            'amount' => 2500,
            'currency' => 'USD',
            'created_at' => now()->startOfWeek()->addDay(),
            'completed_at' => now()->startOfWeek()->addDay(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/goals?period=weekly&platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('defaults.0.metric', 'revenue')
            ->assertJsonPath('defaults.0.target_currency', 'USD')
            ->assertJsonPath('defaults.0.progress.0.current', 2500)
            ->assertJsonPath('defaults.0.progress.0.percentage', 25);
    }

    public function test_manager_cannot_assign_sales_only_metric_to_marketing_user(): void
    {
        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform();
        $marketingUser = $this->createTeamUser('marketing', [$platform->id], ['email' => 'marketing-goal@example.test']);

        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/team/goals/overrides', [
            'user_id' => $marketingUser->id,
            'metric' => 'subs_activated',
            'target' => 4,
            'period' => 'weekly',
            'platform_id' => $platform->id,
        ])->assertStatus(422);
    }

    public function test_goal_assignable_agents_include_sub_admin_sales_and_marketing(): void
    {
        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform();
        $this->createTeamUser('admin', [], ['email' => 'assignable-admin@example.test']);
        $subAdmin = $this->createTeamUser('sub_admin', [$platform->id], ['email' => 'assignable-subadmin@example.test']);
        $salesUser = $this->createTeamUser('sales', [$platform->id], ['email' => 'assignable-sales@example.test']);
        $marketingUser = $this->createTeamUser('marketing', [$platform->id], ['email' => 'assignable-marketing@example.test']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/goals?period=weekly&platform_id=' . $platform->id);

        $response->assertOk();

        $assignableIds = collect($response->json('assignable_agents'))
            ->pluck('user_id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([$subAdmin->id, $salesUser->id, $marketingUser->id], $assignableIds);
    }
}
