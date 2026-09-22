<?php

namespace Tests\Feature\Mcp;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\McpOAuthClient;
use App\Models\User;
use App\Services\Mcp\McpStaffAliasService;
use App\Services\Mcp\Presenters\AgentPerformancePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class McpOAuthAndGrantTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_authorization_code_pkce_refresh_and_revoke_are_read_only(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $registration = $this->postJson('/api/mcp/oauth/register', ['client_name' => 'ChatGPT test', 'redirect_uris' => ['https://connector.example.test/callback']])->assertCreated();
        $clientId = $registration->json('client_id');
        $verifier = str_repeat('p', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        Sanctum::actingAs($user);
        $redirect = $this->get('/api/mcp/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => 'https://connector.example.test/callback', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'mcp:read', 'state' => 'state-123', 'approved' => 1]));
        $redirect->assertRedirect();
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $token = $this->postJson('/api/mcp/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $query['code'], 'redirect_uri' => 'https://connector.example.test/callback', 'code_verifier' => $verifier])->assertOk();
        $token->assertJsonPath('token_type', 'Bearer')->assertJsonPath('scope', 'mcp:read');
        $access = $token->json('access_token');
        $this->withToken($access)->getJson('/api/platforms')->assertForbidden();
        $this->withoutToken();
        $refresh = $this->postJson('/api/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $token->json('refresh_token')])->assertOk();
        $this->postJson('/api/mcp/oauth/revoke', ['token' => $refresh->json('refresh_token')])->assertOk();
        $this->postJson('/api/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $clientId, 'refresh_token' => $refresh->json('refresh_token')])->assertNotFound();
    }

    public function test_browser_authorization_endpoint_uses_the_existing_crm_web_session(): void
    {
        Config::set('mcp.enabled', true);
        $user = User::factory()->create(['role' => 'admin']);
        $client = McpOAuthClient::query()->create(['client_id' => '8c40d3e9-ef4d-46ec-ae8f-5614d0dc9104', 'client_name' => 'Claude test', 'redirect_uris' => ['https://connector.example.test/callback'], 'active' => true]);
        $verifier = str_repeat('q', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $metadata = $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
        $this->assertSame(url('/mcp/oauth/authorize'), $metadata->json('authorization_endpoint'));
        $redirect = $this->actingAs($user, 'web')->get('/mcp/oauth/authorize?'.http_build_query(['response_type' => 'code', 'client_id' => $client->client_id, 'redirect_uri' => 'https://connector.example.test/callback', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'mcp:read', 'approved' => 1]));
        $redirect->assertRedirect();
        $this->assertStringContainsString('code=', (string) $redirect->headers->get('Location'));
    }

    public function test_administrator_edits_a_minted_grant_without_rotating_its_secret(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $minted = $this->postJson('/api/crm/settings/mcp/tokens', ['label' => 'remote connector', 'tools' => ['exotic_catalog']])->assertCreated();
        // Sanctum's plaintext suffix is not the token ID; resolve the minted row.
        $tokenId = (int) \Laravel\Sanctum\PersonalAccessToken::query()->where('name', 'mcp:remote connector')->value('id');
        $this->patchJson('/api/crm/settings/mcp/tokens/'.$tokenId, ['tools' => ['exotic_catalog', 'exotic_client_operations'], 'identified_clients' => true])->assertOk()->assertJsonPath('secret_rotated', false)->assertJsonPath('grant_version', 1);
        $this->patchJson('/api/crm/settings/mcp/tokens/'.$tokenId, ['daily_rows' => 123])->assertOk()->assertJsonPath('capabilities.tools.0', 'exotic_catalog')->assertJsonPath('limits.token_rows', 123);
        $this->assertDatabaseHas('mcp_token_grants', ['token_id' => $tokenId, 'grant_version' => 2]);
        $this->assertTrue(AuditLog::query()->where('action', 'mcp_token_grant_update')->exists());
    }

    public function test_identified_client_rows_require_the_capability_confirmation_and_audited_purpose(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        $admin = User::factory()->create(['role' => 'admin']);
        $client = Client::factory()->create(['name' => 'Protected Client', 'phone_normalized' => '254700000000', 'email' => 'protected@example.test']);
        $denied = $admin->createToken('mcp:no-identified-clients', ['mcp:read', 'mcp:tool:exotic_client_operations'])->plainTextToken;

        $this->analyticsCall($denied, ['identified' => true, 'confirm_identified' => true, 'purpose' => 'payment triage'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', -32011);

        $allowed = $admin->createToken('mcp:identified-clients', ['mcp:read', 'mcp:tool:exotic_client_operations', 'mcp:identified-clients'])->plainTextToken;
        $response = $this->analyticsCall($allowed, ['identified' => true, 'confirm_identified' => true, 'purpose' => 'payment triage'])
            ->assertOk()
            ->assertJsonPath('result.structuredContent.data.rows.0.client_name', $client->name);
        $json = $response->getContent();
        $this->assertStringNotContainsString($client->phone_normalized, $json);
        $this->assertStringNotContainsString($client->email, $json);
        $this->assertDatabaseHas('audit_log', ['actor_id' => $admin->id, 'action' => 'mcp_identified_client_rows']);
    }

    public function test_staff_identity_is_projected_to_an_administrator_managed_alias(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['name' => 'Benja Full Name', 'role' => 'sales']);
        app(McpStaffAliasService::class)->save($staff, 'benja', true, $admin);

        $result = app(AgentPerformancePresenter::class)->present(['agents' => [['id' => $staff->id, 'name' => $staff->name, 'role' => 'sales']]], $admin);

        $this->assertSame('benja', $result['agents'][0]['agent_alias']);
        $this->assertArrayNotHasKey('id', $result['agents'][0]);
        $this->assertArrayNotHasKey('name', $result['agents'][0]);
    }

    private function analyticsCall(string $token, array $arguments)
    {
        return $this->withToken($token)->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => random_int(1, 99999),
            'method' => 'tools/call',
            'params' => [
                'name' => 'exotic_client_operations',
                'arguments' => $arguments,
                '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'],
            ],
        ], ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2026-07-28']);
    }
}
