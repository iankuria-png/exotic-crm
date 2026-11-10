<?php

namespace Tests\Feature\Mcp;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Tests\Feature\Collections\CollectionWorkflowTestCase;

class McpOperationsFeedTest extends CollectionWorkflowTestCase
{
    public function test_wallet_balance_answers_with_the_latest_sms_stated_balance(): void
    {
        [$account, $device] = $this->purchase(false);
        $this->received($device, 'FIRST123456');
        $this->received($device, 'SECOND12345');
        $admin = $this->mcpUser('admin');

        $response = $this->rpc($admin, 'exotic_wallet_balance')->assertOk();

        $response->assertJsonPath('result.structuredContent.data.rows.0.wallet', $account->label)
            ->assertJsonPath('result.structuredContent.data.rows.0.currency', 'TZS')
            ->assertJsonPath('result.structuredContent.data.rows.0.balance_display', 'TZS 900,000.00')
            ->assertJsonPath('result.structuredContent.meta.row_count', 1);
        $this->assertEquals(900000, $response->json('result.structuredContent.data.rows.0.balance'));
        $this->assertStringEndsWith('EAT', $response->json('result.structuredContent.data.rows.0.reported_at_eat'));
        $this->assertStringNotContainsString('255700000001', $response->getContent());
    }

    public function test_wallet_balance_is_hidden_from_out_of_market_staff(): void
    {
        [, $device] = $this->purchase(false);
        $this->received($device, 'FIRST123456');
        $other = Platform::factory()->create();
        $sales = $this->mcpUser('sales');
        $sales->platforms()->sync([$other->id]);

        $this->rpc($sales, 'exotic_wallet_balance')->assertOk()
            ->assertJsonPath('result.structuredContent.data.rows', [])
            ->assertJsonPath('result.structuredContent.meta.result_state', 'no_data');
    }

    public function test_recent_payments_lists_newest_first_with_limit_and_no_raw_identifiers(): void
    {
        $platform = Platform::factory()->create(['name' => 'Tanzania', 'country' => 'TZ', 'currency_code' => 'TZS']);
        $client = Client::factory()->create(['platform_id' => $platform->id, 'name' => 'Naila Profile']);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'name' => 'VIP']);
        foreach ([3, 1, 2] as $hoursAgo) {
            Payment::factory()->create(['platform_id' => $platform->id, 'product_id' => $product->id, 'client_id' => $client->id, 'amount' => 1000 * $hoursAgo, 'currency' => 'TZS', 'completed_at' => now()->subHours($hoursAgo)]);
        }
        Payment::factory()->create(['platform_id' => $platform->id, 'product_id' => $product->id, 'status' => 'pending', 'completed_at' => null]);
        $admin = $this->mcpUser('admin');

        $response = $this->rpc($admin, 'exotic_recent_payments', ['limit' => 2])->assertOk();

        $rows = $response->json('result.structuredContent.data.rows');
        $this->assertCount(2, $rows);
        $this->assertEquals([1000, 2000], array_column($rows, 'amount'));
        $this->assertSame('Tanzania', $rows[0]['market']);
        $this->assertStringStartsWith('cli_', $rows[0]['client_handle'] ?? 'cli_');
        $this->assertStringNotContainsString('Naila Profile', $response->getContent());
    }

    public function test_recent_payments_names_profiles_only_on_an_identified_admin_grant(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'name' => 'Naila Profile']);
        Payment::factory()->create(['platform_id' => $platform->id, 'client_id' => $client->id]);
        $admin = $this->mcpUser('admin');
        $args = ['identified' => true, 'purpose' => 'Review latest payments'];

        $this->rpc($admin, 'exotic_recent_payments', $args)->assertStatus(403);
        $this->rpc($admin, 'exotic_recent_payments', $args, ['mcp:identified-clients'])->assertOk()
            ->assertJsonPath('result.structuredContent.data.rows.0.profile_name', 'Naila Profile');
        $this->assertDatabaseHas('audit_log', ['action' => 'mcp_identified_client_rows', 'entity_type' => 'mcp_operations_feed']);
    }

    public function test_recent_subscriptions_lists_latest_activations_in_scope(): void
    {
        $platform = Platform::factory()->create(['name' => 'Tanzania']);
        $other = Platform::factory()->create(['name' => 'Kenya']);
        $client = Client::factory()->create(['platform_id' => $platform->id]);
        $otherClient = Client::factory()->create(['platform_id' => $other->id]);
        Deal::factory()->create(['platform_id' => $platform->id, 'client_id' => $client->id, 'status' => 'active', 'plan_type' => 'VIP', 'activated_at' => now()->subHour(), 'expires_at' => now()->addDays(7)]);
        Deal::factory()->create(['platform_id' => $platform->id, 'client_id' => $client->id, 'status' => 'expired', 'plan_type' => 'PREMIUM', 'activated_at' => now()->subDays(20), 'expires_at' => now()->subDays(5)]);
        Deal::factory()->create(['platform_id' => $other->id, 'client_id' => $otherClient->id, 'status' => 'active', 'activated_at' => now()->subMinutes(5), 'expires_at' => now()->addDays(7)]);
        $subAdmin = $this->mcpUser('sub_admin');
        $subAdmin->platforms()->sync([$platform->id]);

        $response = $this->rpc($subAdmin, 'exotic_recent_subscriptions', ['limit' => 10])->assertOk();

        $rows = $response->json('result.structuredContent.data.rows');
        $this->assertCount(2, $rows);
        $this->assertSame(['vip', 'premium'], array_column($rows, 'plan_type'));
        $this->assertSame(['Tanzania', 'Tanzania'], array_column($rows, 'market'));

        $active = $this->rpc($subAdmin, 'exotic_recent_subscriptions', ['status' => 'active'])->assertOk();
        $this->assertCount(1, $active->json('result.structuredContent.data.rows'));
    }

    private function mcpUser(string $role): User
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);

        return User::factory()->create(['role' => $role]);
    }

    private function rpc(User $user, string $tool, array $arguments = [], array $extraAbilities = [])
    {
        $token = $user->createToken('mcp:operations-feed', array_merge(['mcp:read', 'mcp:tool:'.$tool], $extraAbilities))->plainTextToken;

        return $this->withToken($token)->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => random_int(1, 99999),
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments, '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ], ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2026-07-28']);
    }
}
