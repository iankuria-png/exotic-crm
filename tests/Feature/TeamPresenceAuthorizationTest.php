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

    public function test_admin_presence_lists_online_agents_but_not_manager_accounts(): void
    {
        $platform = $this->createTeamPlatform();
        $admin = $this->createTeamUser('admin');
        $sales = $this->createTeamUser('sales', [$platform->id], ['email' => 'sales@example.test']);
        $marketing = $this->createTeamUser('marketing', [$platform->id], ['email' => 'marketing@example.test']);
        $subAdmin = $this->createTeamUser('sub_admin', [$platform->id], ['email' => 'subadmin@example.test']);

        $this->createTeamSession($sales, '55555555-5555-5555-5555-555555555555');
        $this->createTeamSession($marketing, '66666666-6666-6666-6666-666666666666');
        $this->createTeamSession($subAdmin, '77777777-7777-7777-7777-777777777777');

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/team/presence');

        $response->assertOk();
        $this->assertSame(2, count($response->json('data')));
        $this->assertSame(2, (int) $response->json('summary.online_now'));

        $roles = collect($response->json('data'))->pluck('role')->all();
        $this->assertSame(['marketing', 'sales'], collect($roles)->sort()->values()->all());
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

    public function test_sales_user_cannot_access_presence_route(): void
    {
        $platform = $this->createTeamPlatform();
        $sales = $this->createTeamUser('sales', [$platform->id]);

        Sanctum::actingAs($sales);

        $this->getJson('/api/crm/team/presence')->assertStatus(403);
    }
}
