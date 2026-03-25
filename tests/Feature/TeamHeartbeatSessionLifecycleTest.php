<?php

namespace Tests\Feature;

use App\Models\AgentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTeamActivityFixtures;
use Tests\TestCase;

class TeamHeartbeatSessionLifecycleTest extends TestCase
{
    use InteractsWithTeamActivityFixtures;
    use RefreshDatabase;

    public function test_heartbeat_creates_and_updates_a_single_session_for_the_same_tab(): void
    {
        $platform = $this->createTeamPlatform();
        $user = $this->createTeamUser('sales', [$platform->id]);
        Sanctum::actingAs($user);

        $sessionToken = '11111111-1111-1111-1111-111111111111';

        $this->postJson('/api/crm/heartbeat', [
            'session_token' => $sessionToken,
        ])->assertNoContent();

        $session = AgentSession::query()->firstOrFail();
        $startedAt = $session->started_at;

        $this->travel(30)->seconds();

        $this->postJson('/api/crm/heartbeat', [
            'session_token' => $sessionToken,
        ])->assertNoContent();

        $this->assertDatabaseCount('agent_sessions', 1);

        $session->refresh();
        $this->assertSame($user->id, (int) $session->user_id);
        $this->assertNull($session->ended_at);
        $this->assertTrue($session->last_heartbeat_at->gt($startedAt));
    }

    public function test_same_session_token_is_closed_for_the_old_user_before_new_user_claims_it(): void
    {
        $platform = $this->createTeamPlatform();
        $userA = $this->createTeamUser('sales', [$platform->id], ['email' => 'a@example.test']);
        $userB = $this->createTeamUser('sales', [$platform->id], ['email' => 'b@example.test']);
        $sessionToken = '22222222-2222-2222-2222-222222222222';

        Sanctum::actingAs($userA);
        $this->postJson('/api/crm/heartbeat', [
            'session_token' => $sessionToken,
        ])->assertNoContent();

        Sanctum::actingAs($userB);
        $this->postJson('/api/crm/heartbeat', [
            'session_token' => $sessionToken,
        ])->assertNoContent();

        $this->assertDatabaseCount('agent_sessions', 2);

        $oldSession = AgentSession::query()
            ->where('user_id', $userA->id)
            ->firstOrFail();

        $newSession = AgentSession::query()
            ->where('user_id', $userB->id)
            ->firstOrFail();

        $this->assertNotNull($oldSession->ended_at);
        $this->assertNull($newSession->ended_at);
    }

    public function test_logout_closes_only_the_current_tab_session(): void
    {
        $platform = $this->createTeamPlatform();
        $user = $this->createTeamUser('sales', [$platform->id]);
        $tabOne = '33333333-3333-3333-3333-333333333333';
        $tabTwo = '44444444-4444-4444-4444-444444444444';

        $this->createTeamSession($user, $tabOne);
        $this->createTeamSession($user, $tabTwo);

        Sanctum::actingAs($user);

        $this->postJson('/api/crm/logout', [
            'session_token' => $tabOne,
        ])->assertOk();

        $sessionOne = AgentSession::query()->where('session_token', $tabOne)->firstOrFail();
        $sessionTwo = AgentSession::query()->where('session_token', $tabTwo)->firstOrFail();

        $this->assertNotNull($sessionOne->ended_at);
        $this->assertNull($sessionTwo->ended_at);
    }
}
