<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class McpKnowledgeLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_2025_clients_receive_enabled_contract_features(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        Config::set('mcp.waves.knowledge', true);
        $user = User::factory()->create(['role' => 'admin']);
        $plain = $user->createToken('mcp:knowledge', ['mcp:read', 'mcp:tool:exotic_search_knowledge'], now()->addDay())->plainTextToken;

        $response = $this->withToken($plain)->postJson('/api/mcp', $this->request('tools/list'), $this->headers('tools/list'));

        $response->assertOk()
            ->assertJsonPath('result.tools.0.name', 'exotic_search_knowledge')
            ->assertJsonPath('result.tools.0.annotations.readOnlyHint', true)
            ->assertJsonPath('result._meta.exotic/cacheScope', 'private');
        $this->assertSame('exotic-crm', $response->json('result._meta')['io.modelcontextprotocol/serverInfo']['name']);
        $this->assertSame('object', $response->json('result.tools.0.outputSchema.type'));
    }

    public function test_2026_is_rejected_as_an_unsupported_protocol_version(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        $user = User::factory()->create(['role' => 'admin']);
        $plain = $user->createToken('mcp:knowledge', ['mcp:read'], now()->addDay())->plainTextToken;

        $this->withToken($plain)->postJson('/api/mcp', $this->request('tools/list'), $this->headers('tools/list', '2026-07-28'))
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32022);
    }

    public function test_admin_can_activate_the_initial_ontology_release(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/settings/mcp/knowledge/bootstrap')
            ->assertOk()
            ->assertJsonPath('release.ontology_version', '1.0.0')
            ->assertJsonPath('release.active_slot', 1);
        $this->assertSame('1.0.0', app(\App\Services\Mcp\Knowledge\OntologyRegistry::class)->active()['version']);
    }

    private function request(string $method): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2025-06-18']]];
    }

    private function headers(string $method, string $version = '2025-06-18'): array
    {
        return ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => $version, 'Mcp-Method' => $method];
    }
}
