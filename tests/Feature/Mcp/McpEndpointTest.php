<?php

namespace Tests\Feature\Mcp;

use App\Models\IntegrationSetting;
use App\Models\McpToolCall;
use App\Models\User;
use App\Services\Mcp\McpSettingsService;
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

        $response = $this->withHeaders($this->modernHeaders('server/discover', '2025-06-18'))
            ->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'server/discover',
                'params' => [
                    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18'],
                ],
            ], $this->modernHeaders('server/discover', '2025-06-18'));

        $response->assertOk();
        $this->assertSame('exotic-crm', $response->json('result._meta')['io.modelcontextprotocol/serverInfo']['name']);
        $this->assertDatabaseHas('mcp_tool_calls', [
            'tool' => 'rpc:server/discover',
            'status' => 'success',
            'user_id' => $user->id,
        ]);
    }

    public function test_unknown_protocol_version_is_rejected_as_unsupported(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:unsupported', ['mcp:read'], now()->addDay())->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 2,
                'method' => 'server/discover',
                'params' => [
                    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18'],
                ],
            ], $this->modernHeaders('server/discover', '2024-11-05'))
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32022)
            ->assertJsonPath('error.message', 'Unsupported MCP protocol version "2024-11-05" in the MCP-Protocol-Version header. Supported versions: 2025-06-18, 2025-03-26.');
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

    public function test_codex_can_initialize_and_list_tools_with_standard_streamable_http_requests(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:codex', ['mcp:read'], now()->addDay())->plainTextToken;
        $headers = [
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2025-06-18',
        ];

        $this->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 3,
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => '2025-06-18',
                    'capabilities' => [],
                    'clientInfo' => ['name' => 'codex', 'version' => '0.153.4'],
                ],
            ], $headers)
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18');

        $this->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ], $headers)
            ->assertAccepted()
            ->assertNoContent(202);

        $response = $this->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/list',
                'params' => [],
            ], $headers)
            ->assertOk();

        $this->assertContains('exotic_catalog', collect($response->json('result.tools'))->pluck('name')->all());
    }

    public function test_saved_settings_cannot_remove_supported_protocol_defaults(): void
    {
        IntegrationSetting::query()->create([
            'key' => McpSettingsService::KEY,
            'value' => ['protocol_versions' => ['2024-11-05', '2025-03-26']],
        ]);

        $versions = app(McpSettingsService::class)->settings()['protocol_versions'];

        $this->assertContains('2025-06-18', $versions);
        $this->assertNotContains('2024-11-05', $versions);
    }

    public function test_tools_list_honours_role_and_token_allowlist(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:limited', ['mcp:read', 'mcp:tool:exotic_catalog'], now()->addDay())->plainTextToken;

        $response = $this->withHeaders($this->modernHeaders('tools/list', '2025-06-18'))
            ->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 4,
                'method' => 'tools/list',
                'params' => [
                    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18'],
                ],
            ], $this->modernHeaders('tools/list', '2025-06-18'));

        $response->assertOk();
        $names = collect($response->json('result.tools'))->pluck('name')->all();
        $this->assertSame(['exotic_catalog'], $names);
    }

    public function test_expired_token_refusal_is_audited(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:expired', ['mcp:read'], now()->subMinute())->plainTextToken;

        $this->withHeaders($this->modernHeaders('server/discover', '2025-06-18'))
            ->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 5,
                'method' => 'server/discover',
                'params' => [
                    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18'],
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

    public function test_settings_registry_includes_disabled_tools_for_management(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/settings/mcp')->assertOk();
        $sqlTool = collect($response->json('tools'))->firstWhere('name', 'exotic_run_reporting_sql');

        $this->assertNotNull($sqlTool);
        $this->assertFalse($sqlTool['enabled']);
        $this->assertSame('schema', $sqlTool['domain']);
    }

    public function test_enhanced_capabilities_are_visible_in_the_management_registry_and_default_token(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        Config::set('mcp.waves.knowledge', true);
        Config::set('mcp.waves.diagnostics', true);
        Config::set('mcp.tools.exotic_run_reporting_sql.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $this->getJson('/api/crm/settings/mcp')
            ->assertOk()
            ->assertJsonCount(21, 'tools')
            ->assertJsonPath('tools.15.name', 'exotic_search_knowledge')
            ->assertJsonPath('tools.15.management_mode', 'rollout')
            ->assertJsonPath('tools.15.scope_required', true);

        $minted = $this->postJson('/api/crm/settings/mcp/tokens', ['label' => 'full access'])
            ->assertCreated()
            ->assertJsonCount(22, 'abilities')
            ->assertJsonPath('abilities.0', 'mcp:read');
        $token = $minted->json('token');

        $response = $this->withToken($token)->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => 8,
            'method' => 'tools/list',
            'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18']],
        ], $this->modernHeaders('tools/list', '2025-06-18'));

        $response->assertOk()->assertJsonCount(21, 'result.tools');
        $this->assertContains('exotic_search_knowledge', collect($response->json('result.tools'))->pluck('name')->all());
        $this->assertContains('exotic_error_digest_live', collect($response->json('result.tools'))->pluck('name')->all());
    }

    public function test_admin_can_preview_a_sanitised_catalog_payload(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($user);

        $this->postJson('/api/crm/settings/mcp/tools/exotic_catalog/preview', ['arguments' => []])
            ->assertOk()
            ->assertJsonPath('tool', 'exotic_catalog')
            ->assertJsonPath('pii_scan.clean', true)
            ->assertJsonStructure(['payload', 'bytes', 'row_count', 'pii_scan' => ['clean', 'fields_checked']]);
    }

    public function test_activity_exposes_latency_token_and_tool_summary(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:browser', ['mcp:read'], now()->addDay())->accessToken;
        McpToolCall::create([
            'token_id' => $token->id,
            'user_id' => $user->id,
            'tool' => 'exotic_catalog',
            'status' => 'success',
            'row_count' => 2,
            'bytes_out' => 128,
            'latency_ms' => 48,
            'created_at' => now(),
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/crm/settings/mcp/activity')
            ->assertOk()
            ->assertJsonPath('summary.calls_today', 1)
            ->assertJsonPath('summary.p95_latency_ms', 48)
            ->assertJsonPath('rows.0.token_label', 'browser')
            ->assertJsonPath('tool_stats.exotic_catalog.calls_7d', 1);
    }

    public function test_tools_list_result_only_contains_spec_fields(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:spec', ['mcp:read'], now()->addDay())->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 7,
                'method' => 'tools/list',
                'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18']],
            ], $this->modernHeaders('tools/list', '2025-06-18'))
            ->assertOk();

        $result = $response->json('result');
        $this->assertSame([], array_diff(array_keys($result), ['tools', 'nextCursor', '_meta']));
        $this->assertArrayNotHasKey('resultType', $result);
        $this->assertArrayNotHasKey('row_count', $result);
        $this->assertSame('complete', $result['_meta']['exotic/resultType']);
    }

    public function test_resources_list_result_only_contains_spec_fields(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:spec', ['mcp:read'], now()->addDay())->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 8,
                'method' => 'resources/list',
                'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18']],
            ], $this->modernHeaders('resources/list', '2025-06-18'))
            ->assertOk();

        $result = $response->json('result');
        $this->assertSame([], array_diff(array_keys($result), ['resources', 'nextCursor', '_meta']));
        $this->assertArrayNotHasKey('resultType', $result);
    }

    public function test_tools_call_result_only_contains_spec_fields_and_still_audits_rows(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:spec', ['mcp:read'], now()->addDay())->plainTextToken;

        $headers = $this->modernHeaders('tools/call', '2025-06-18') + ['Mcp-Name' => 'exotic_catalog'];
        $response = $this->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 9,
                'method' => 'tools/call',
                'params' => [
                    'name' => 'exotic_catalog',
                    'arguments' => [],
                    '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18'],
                ],
            ], $headers)
            ->assertOk();

        $result = $response->json('result');
        $this->assertSame([], array_diff(array_keys($result), ['content', 'structuredContent', 'isError', '_meta']));
        $this->assertArrayNotHasKey('resultType', $result);
        $this->assertArrayNotHasKey('row_count', $result);
        $this->assertSame('complete', $result['_meta']['exotic/resultType']);
        $this->assertIsInt($result['_meta']['exotic/rowCount']);

        // The audit trail still records the row count now that it lives under _meta.
        $this->assertDatabaseHas('mcp_tool_calls', [
            'tool' => 'exotic_catalog',
            'status' => 'success',
            'row_count' => $result['_meta']['exotic/rowCount'],
        ]);
    }

    public function test_unsupported_protocol_version_names_the_supported_versions(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:spec', ['mcp:read'], now()->addDay())->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 10,
                'method' => 'tools/list',
                'params' => [],
            ], $this->modernHeaders('tools/list', '2026-07-28'))
            ->assertStatus(400);

        $response->assertJsonPath('error.code', -32022);
        $message = $response->json('error.message');
        $this->assertStringContainsString('2026-07-28', $message);
        $this->assertStringContainsString('2025-06-18', $message);
        $this->assertStringContainsString('2025-03-26', $message);
    }

    public function test_parameterless_tools_expose_properties_as_a_json_object(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:spec', ['mcp:read'], now()->addDay())->plainTextToken;

        $response = $this->withToken($token)
            ->postJson('/api/mcp', [
                'jsonrpc' => '2.0',
                'id' => 11,
                'method' => 'tools/list',
                'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18']],
            ], $this->modernHeaders('tools/list', '2025-06-18'))
            ->assertOk();

        // exotic_catalog, exotic_system_vitals and exotic_market_health take no arguments.
        $raw = $response->getContent();
        $this->assertStringNotContainsString('"properties":[]', $raw);
        $this->assertStringContainsString('"properties":{}', $raw);

        foreach ($response->json('result.tools') as $tool) {
            $this->assertIsArray($tool['inputSchema']['properties'], $tool['name'].' decoded to a list, not an object');
        }
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
