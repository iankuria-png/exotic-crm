<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class McpEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_modern_discovery_requires_matching_transport_metadata(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:test', ['mcp:read'], now()->addDay())->plainTextToken;

        $response = $this->withHeaders($this->modernHeaders('server/discover', '2026-07-28'))
            ->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'server/discover',
                'params' => [
                    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'],
                ],
            ], $this->modernHeaders('server/discover', '2026-07-28'));

        $response->assertOk();
        $this->assertSame('exotic-crm', $response->json('result._meta')['io.modelcontextprotocol/serverInfo']['name']);
        $this->assertDatabaseHas('mcp_tool_calls', [
            'tool' => 'rpc:server/discover',
            'status' => 'success',
            'user_id' => $user->id,
        ]);
    }

    public function test_legacy_initialize_flow_is_isolated_and_wildcard_tokens_are_rejected(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $legacyToken = $user->createToken('mcp:legacy', ['mcp:read'], now()->addDay())->plainTextToken;

        $this->withToken($legacyToken)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'initialize',
                'params' => [],
            ])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-03-26');

        $this->withToken($legacyToken)
            ->getJson('/api/platforms')
            ->assertForbidden();

        $wildcard = $user->createToken('crm-session', ['*'])->plainTextToken;
        $this->withToken($wildcard)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'initialize',
                'params' => [],
            ])
            ->assertUnauthorized();
    }

    public function test_tools_list_honours_role_and_token_allowlist(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:limited', ['mcp:read', 'mcp:tool:exotic_catalog'], now()->addDay())->plainTextToken;

        $response = $this->withHeaders($this->modernHeaders('tools/list', '2026-07-28'))
            ->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/list',
                'params' => [
                    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'],
                ],
            ], $this->modernHeaders('tools/list', '2026-07-28'));

        $response->assertOk();
        $names = collect($response->json('result.tools'))->pluck('name')->all();
        $this->assertSame(['exotic_catalog'], $names);
    }

    public function test_expired_token_refusal_is_audited(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:expired', ['mcp:read'], now()->subMinute())->plainTextToken;

        $this->withHeaders($this->modernHeaders('server/discover', '2026-07-28'))
            ->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 5,
                'method' => 'server/discover',
                'params' => [
                    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'],
                ],
            ])
            ->assertUnauthorized();

        $this->assertDatabaseHas('mcp_tool_calls', [
            'tool' => 'rpc:authentication',
            'status' => 'refused',
            'refusal_reason' => 'expired',
        ]);
    }

    public function test_settings_write_creates_a_system_audit_record(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $this->putJson('/api/crm/settings/mcp', ['enabled' => true])
            ->assertOk();

        $this->assertDatabaseHas('audit_log', [
            'entity_type' => 'ops_mcp_config',
            'action' => 'mcp_config_update',
        ]);
    }

    private function modernHeaders(string $method, string $version): array
    {
        return [
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => $version,
            'Mcp-Method' => $method,
        ];
    }
}
