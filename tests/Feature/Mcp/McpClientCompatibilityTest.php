<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Services\Mcp\McpSettingsService;
use App\Services\Mcp\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class McpClientCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_2026_stateless_discovery_lists_and_executes_every_permitted_tool(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        Config::set('mcp.waves.knowledge', true);
        Config::set('mcp.waves.diagnostics', true);
        $user = User::factory()->create(['role' => 'admin']);
        $abilities = array_merge(['mcp:read'], array_map(fn ($name) => 'mcp:tool:'.$name, app(ToolRegistry::class)->defaultToolNames($user, app(McpSettingsService::class))));
        $token = $user->createToken('mcp:modern-conformance', $abilities, now()->addDay())->plainTextToken;

        $discover = $this->rpc($token, 'server/discover', [])->assertOk();
        $this->assertSame('exotic-crm', $discover->json('result._meta')['io.modelcontextprotocol/serverInfo']['name']);
        $listed = $this->rpc($token, 'tools/list', [])->assertOk()->json('result.tools');
        $this->assertNotEmpty($listed);
        foreach ($listed as $tool) {
            $response = $this->rpc($token, 'tools/call', ['name' => $tool['name'], 'arguments' => $this->fixtureFor($tool['name'])]);
            $this->assertNotContains($response->json('error.code'), [-32601, -32603], $tool['name'].' must have an executable dispatch and safe failure semantics.');
        }
    }

    public function test_2026_resource_read_matches_document_tool_for_each_discoverable_context_resource(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        Config::set('mcp.waves.knowledge', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:resource-parity', ['mcp:read', 'mcp:tool:exotic_get_document'], now()->addDay())->plainTextToken;
        $resources = $this->rpc($token, 'resources/list', [])->assertOk()->json('result.resources');
        foreach (array_filter($resources, fn ($resource) => str_starts_with($resource['uri'], 'exotic://context/')) as $resource) {
            $read = $this->rpc($token, 'resources/read', ['uri' => $resource['uri']])->assertOk()->json('result.contents.0.text');
            $document = $this->rpc($token, 'tools/call', ['name' => 'exotic_get_document', 'arguments' => ['uri' => $resource['uri']]])->assertOk()->json('result.structuredContent.data.document');
            $this->assertSame($read, $document);
        }
    }

    public function test_legacy_initialize_notification_list_and_call_remain_supported(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('mcp:legacy-compatibility', ['mcp:read'], now()->addDay())->plainTextToken;
        $headers = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2025-06-18'];
        $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']], $headers)->assertOk();
        $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'method' => 'notifications/initialized', 'params' => []], $headers)->assertAccepted();
        $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], $headers)->assertOk();
        $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'exotic_catalog', 'arguments' => []]], $headers)->assertOk();
    }

    private function rpc(string $token, string $method, array $params)
    {
        $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'];

        return $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => random_int(1, 99999), 'method' => $method, 'params' => $params], ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2026-07-28']);
    }

    private function fixtureFor(string $name): array
    {
        return match ($name) {
            'exotic_get_document' => ['uri' => 'exotic://context/conventions'],
            'exotic_search_knowledge' => ['query' => 'payments'],
            'exotic_payment_flow_trace', 'exotic_payment_failure_diagnosis' => ['locator' => 'pay_abcdefghijklmnop'],
            'exotic_error_digest_live' => ['limit' => 1],
            'exotic_revenue_summary', 'exotic_revenue_trend', 'exotic_market_breakdown', 'exotic_render_revenue_dashboard', 'exotic_agent_performance', 'exotic_peak_hours', 'exotic_ceo_dashboard' => ['window' => '7d'],
            'exotic_lifecycle_summary' => [],
            'exotic_churn_analysis' => ['from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString()],
            'exotic_cohort_retention' => [],
            'exotic_client_snapshot' => ['handle' => 'cli_0000000000000000'],
            'exotic_team_performance' => ['period' => 'week'],
            'exotic_visitor_demand' => ['range' => '7d'],
            'exotic_commission_summary' => ['days' => 7],
            'exotic_client_operations' => ['queue' => 'all', 'limit' => 1],
            'exotic_lifecycle_intelligence' => ['from' => now()->subDays(7)->toDateString(), 'to' => now()->toDateString()],
            'exotic_city_performance' => ['platform_id' => 1],
            default => [],
        };
    }
}
