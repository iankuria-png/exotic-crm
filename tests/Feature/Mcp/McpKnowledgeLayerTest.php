<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class McpKnowledgeLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_2026_requires_an_explicit_protocol_grant_and_exposes_only_granted_tools(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts_2026', true);
        Config::set('mcp.waves.knowledge', true);
        $user = User::factory()->create(['role' => 'admin']);
        $plain = $user->createToken('mcp:modern', ['mcp:read', 'mcp:protocol:2026-07-28', 'mcp:tool:exotic_search_knowledge'], now()->addDay())->plainTextToken;
        $response = $this->withToken($plain)->postJson('/api/mcp', $this->request('tools/list'), $this->headers('tools/list'));
        $response->assertOk()->assertJsonPath('result.tools.0.name', 'exotic_search_knowledge');
        $this->assertSame('exotic-crm', $response->json('result._meta')['io.modelcontextprotocol/serverInfo']['name']);
    }

    public function test_2026_rejects_missing_client_capabilities_before_execution(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts_2026', true);
        $user = User::factory()->create(['role' => 'admin']);
        $plain = $user->createToken('mcp:modern', ['mcp:read', 'mcp:protocol:2026-07-28'], now()->addDay())->plainTextToken;
        $body = $this->request('tools/list');
        unset($body['params']['_meta']['io.modelcontextprotocol/clientCapabilities']);
        $this->withToken($plain)->postJson('/api/mcp', $body, $this->headers('tools/list'))->assertStatus(422)->assertJsonPath('error.code', -32602);
    }

    private function request(string $method): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28', 'io.modelcontextprotocol/clientCapabilities' => []]]];
    }

    private function headers(string $method): array
    {
        return ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
    }
}
