<?php

namespace Tests\Feature;

use App\Models\AgentGoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTeamActivityFixtures;
use Tests\TestCase;

class TeamGoalsTest extends TestCase
{
    use InteractsWithTeamActivityFixtures;
    use RefreshDatabase;

    public function test_admin_can_upsert_and_delete_a_goal(): void
    {
        $admin = $this->createTeamUser('admin');
        $platform = $this->createTeamPlatform();

        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/team/goals', [
            'metric' => 'subs_activated',
            'target' => 10,
            'period' => 'weekly',
            'platform_id' => $platform->id,
        ])->assertCreated()
            ->assertJsonPath('goal.metric', 'subs_activated')
            ->assertJsonPath('goal.target', 10);

        $this->assertDatabaseCount('agent_goals', 1);

        $this->postJson('/api/crm/team/goals', [
            'metric' => 'subs_activated',
            'target' => 12,
            'period' => 'weekly',
            'platform_id' => $platform->id,
        ])->assertCreated()
            ->assertJsonPath('goal.target', 12);

        $this->assertDatabaseCount('agent_goals', 1);

        $goal = AgentGoal::query()->firstOrFail();
        $this->assertSame(12, (int) $goal->target);

        $this->deleteJson('/api/crm/team/goals/' . $goal->id)->assertNoContent();
        $this->assertDatabaseCount('agent_goals', 0);
    }

    public function test_goals_endpoint_returns_per_agent_progress_rows(): void
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
            'set_by' => $admin->id,
        ]);

        $this->createTeamDailyStat($agentA, $platform, now()->startOfWeek(), [
            'subs_activated' => 7,
            'total_actions' => 7,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/goals?period=weekly&platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('data.0.id', $goal->id)
            ->assertJsonPath('data.0.metric', 'subs_activated')
            ->assertJsonPath('data.0.progress.0.current', 7)
            ->assertJsonPath('data.0.progress.0.percentage', 70)
            ->assertJsonPath('data.0.progress.1.current', 0);

        $progressNames = collect($response->json('data.0.progress'))->pluck('name')->all();
        $this->assertSame([$agentA->name, $agentB->name], $progressNames);
    }
}
