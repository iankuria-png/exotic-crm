<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\InteractsWithTeamActivityFixtures;
use Tests\TestCase;

class TeamPresenceAuthorizationTest extends TestCase
{
    use InteractsWithTeamActivityFixtures;
    use RefreshDatabase;

    public function test_admin_presence_lists_team_members_including_manager_accounts(): void
    {
        $platform = $this->createTeamPlatform();
        $admin = $this->createTeamUser('admin');
        $peerAdmin = $this->createTeamUser('admin', [], ['email' => 'peer-admin@example.test']);
        $sales = $this->createTeamUser('sales', [$platform->id], ['email' => 'sales@example.test']);
        $marketing = $this->createTeamUser('marketing', [$platform->id], ['email' => 'marketing@example.test']);
        $subAdmin = $this->createTeamUser('sub_admin', [$platform->id], ['email' => 'subadmin@example.test']);

        $this->createTeamSession($admin, '44444444-4444-4444-4444-444444444444');
        $this->createTeamSession($peerAdmin, '55555555-5555-5555-5555-555555555555');
        $this->createTeamSession($sales, '88888888-8888-8888-8888-888888888888');
        $this->createTeamSession($marketing, '66666666-6666-6666-6666-666666666666');
        $this->createTeamSession($subAdmin, '77777777-7777-7777-7777-777777777777');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/presence');

        $response->assertOk();
        $this->assertSame(5, count($response->json('data')));
        $this->assertSame(5, (int) $response->json('summary.online_now'));

        $roles = collect($response->json('data'))->pluck('role')->sort()->values()->all();
        $this->assertSame(['admin', 'admin', 'marketing', 'sales', 'sub_admin'], $roles);
    }

    public function test_sub_admin_presence_is_limited_to_agents_with_overlapping_market_access(): void
    {
        $platformA = $this->createTeamPlatform(['name' => 'Kenya', 'currency_code' => 'KES']);
        $platformB = $this->createTeamPlatform(['name' => 'Tanzania', 'currency_code' => 'TZS', 'domain' => 'tz-market.test']);
        $subAdmin = $this->createTeamUser('sub_admin', [$platformA->id]);
        $agentA = $this->createTeamUser('sales', [$platformA->id], ['email' => 'a@example.test']);
        $agentB = $this->createTeamUser('sales', [$platformB->id], ['email' => 'b@example.test']);
        $agentBoth = $this->createTeamUser('marketing', [$platformA->id, $platformB->id], ['email' => 'ab@example.test']);

        $this->createTeamSession($agentA, '88888888-8888-8888-8888-888888888888');
        $this->createTeamSession($agentB, '99999999-9999-9999-9999-999999999999');
        $this->createTeamSession($agentBoth, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa');

        Sanctum::actingAs($subAdmin);

        $response = $this->getJson('/api/crm/team/presence');

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('user_id')->sort()->values()->all();
        $this->assertSame([$agentA->id, $agentBoth->id], $ids);
    }

    public function test_presence_can_be_filtered_to_a_single_accessible_market(): void
    {
        $platformA = $this->createTeamPlatform(['name' => 'Kenya', 'currency_code' => 'KES']);
        $platformB = $this->createTeamPlatform(['name' => 'Tanzania', 'currency_code' => 'TZS', 'domain' => 'tz-market.test']);
        $subAdmin = $this->createTeamUser('sub_admin', [$platformA->id, $platformB->id]);
        $agentA = $this->createTeamUser('sales', [$platformA->id], ['email' => 'only-a@example.test']);
        $agentB = $this->createTeamUser('marketing', [$platformB->id], ['email' => 'only-b@example.test']);
        $agentBoth = $this->createTeamUser('sales', [$platformA->id, $platformB->id], ['email' => 'both@example.test']);

        $this->createTeamSession($agentA, '11111111-2222-3333-4444-555555555555');
        $this->createTeamSession($agentB, '66666666-7777-8888-9999-000000000000');
        $this->createTeamSession($agentBoth, 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        Sanctum::actingAs($subAdmin);

        $response = $this->getJson('/api/crm/team/presence?platform_id=' . $platformB->id);

        $response->assertOk();

        $ids = collect($response->json('data'))->pluck('user_id')->sort()->values()->all();
        $this->assertSame([$agentB->id, $agentBoth->id], $ids);
    }

    public function test_sales_user_cannot_access_presence_route(): void
    {
        $platform = $this->createTeamPlatform();
        $sales = $this->createTeamUser('sales', [$platform->id]);

        Sanctum::actingAs($sales);

        $this->getJson('/api/crm/team/presence')->assertStatus(403);
    }
}
