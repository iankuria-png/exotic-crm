<?php

namespace Tests\Feature\Mcp;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\McpOAuthClient;
use App\Models\User;
use App\Services\Mcp\McpTokenGrantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class McpSubscriptionAuditGrantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
    }

    public function test_audit_capability_is_minted_only_for_explicit_admin_tool_selection(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Sanctum::actingAs($admin);
        $default = $this->postJson('/api/crm/settings/mcp/tokens', ['label' => 'default connector'])->assertCreated();
        $this->assertNotContains('mcp:subscription-audit', $default->json('abilities'));
        $this->assertNotContains('mcp:tool:exotic_subscription_audit_summary', $default->json('abilities'));
        $allTools = collect(app(\App\Services\Mcp\ToolRegistry::class)->managementDefinitions($admin, app(\App\Services\Mcp\McpSettingsService::class)))->where('enabled', true)->pluck('name')->all();
        $uiDefault = $this->postJson('/api/crm/settings/mcp/tokens', ['label' => 'all enabled UI tools', 'tools' => $allTools])->assertCreated();
        $this->assertNotContains('mcp:subscription-audit', $uiDefault->json('abilities'));
        $this->assertNotContains('mcp:tool:exotic_subscription_audit_summary', $uiDefault->json('abilities'));
        $minted = $this->postJson('/api/crm/settings/mcp/tokens', ['label' => 'finance audit', 'subscription_audit' => true, 'tools' => ['exotic_subscription_audit_summary']])->assertCreated();
        $this->assertContains('mcp:subscription-audit', $minted->json('abilities'));
        $sales = User::factory()->create(['role' => 'sales']);
        $this->postJson('/api/crm/settings/mcp/tokens', ['label' => 'invalid owner', 'subscription_audit' => true, 'owner_id' => $sales->id, 'tools' => ['exotic_subscription_audit_summary']])->assertStatus(422);
    }

    public function test_grant_edits_preserve_explicit_audit_access_and_remove_it_with_tools(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('mcp:managed-audit', ['mcp:read', 'mcp:tool:exotic_catalog'])->accessToken;
        $grants = app(McpTokenGrantService::class);
        $grants->update($token, $admin, ['tools' => ['exotic_catalog', 'exotic_subscription_audit_summary']]);
        $this->assertContains('mcp:subscription-audit', $token->fresh()->abilities);
        $grants->update($token->fresh(), $admin, ['daily_rows' => 123]);
        $this->assertContains('mcp:subscription-audit', $token->fresh()->abilities);
        $grants->update($token->fresh(), $admin, ['tools' => ['exotic_catalog']]);
        $this->assertNotContains('mcp:subscription-audit', $token->fresh()->abilities);
    }

    public function test_oauth_audit_opt_in_is_explicit_and_requires_the_csrf_protected_post_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$client, $parameters] = $this->clientParameters();
        $this->actingAs($admin, 'web')->get('/mcp/oauth/authorize?'.http_build_query($parameters))
            ->assertOk()->assertSee('method="post"', false)->assertSee('name="_token"', false)
            ->assertSee('name="subscription_audit"', false);
        $this->get('/mcp/oauth/authorize?'.http_build_query($parameters + ['approved' => 1, 'subscription_audit' => 1]))->assertStatus(422);
        $this->assertDatabaseCount('mcp_oauth_authorization_codes', 0);
        $pair = $this->oauthPair($admin, true, $client, $parameters);
        $this->assertContains('mcp:subscription-audit', PersonalAccessToken::findToken($pair['access_token'])->abilities);
        $withoutAudit = $this->oauthPair($admin, false);
        $this->assertNotContains('mcp:subscription-audit', PersonalAccessToken::findToken($withoutAudit['access_token'])->abilities);
    }

    public function test_browser_opt_in_post_rejects_a_missing_csrf_token(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [, $parameters] = $this->clientParameters();
        $this->app->instance(VerifyCsrfToken::class, new class($this->app, $this->app['encrypter']) extends VerifyCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->actingAs($admin, 'web')->post('/mcp/oauth/authorize', $parameters + ['approved' => 1, 'subscription_audit' => 1])->assertStatus(419);
        $this->assertDatabaseCount('mcp_oauth_authorization_codes', 0);
    }

    public function test_oauth_refresh_cannot_restore_removed_audit_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pair = $this->oauthPair($admin, true);
        $parent = PersonalAccessToken::findToken($pair['access_token']);
        app(McpTokenGrantService::class)->update($parent, $admin, ['tools' => ['exotic_catalog']]);
        $fresh = $this->postJson('/api/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $pair['client_id'], 'refresh_token' => $pair['refresh_token']])->assertOk();
        $abilities = PersonalAccessToken::findToken($fresh->json('access_token'))->abilities;
        $this->assertNotContains('mcp:subscription-audit', $abilities);
        $this->assertNotContains('mcp:tool:exotic_subscription_audit_summary', $abilities);
        $this->withToken($fresh->json('access_token'))->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'exotic_subscription_audit_summary', 'arguments' => ['purpose' => 'Finance review']]], ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'])->assertNotFound();
    }

    public function test_deleting_the_backing_grant_prevents_refresh(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pair = $this->oauthPair($admin, true);
        PersonalAccessToken::findToken($pair['access_token'])->delete();
        $this->postJson('/api/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $pair['client_id'], 'refresh_token' => $pair['refresh_token']])->assertNotFound();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_refresh_after_owner_role_change_drops_audit_permissions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pair = $this->oauthPair($admin, true);
        $admin->forceFill(['role' => 'sales'])->save();
        $fresh = $this->postJson('/api/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $pair['client_id'], 'refresh_token' => $pair['refresh_token']])->assertOk();
        $abilities = PersonalAccessToken::findToken($fresh->json('access_token'))->abilities;
        $this->assertNotContains('mcp:subscription-audit', $abilities);
        $this->assertFalse(collect($abilities)->contains(fn ($ability) => str_starts_with($ability, 'mcp:tool:exotic_subscription_audit_')));
    }

    public function test_non_admin_cannot_opt_in_and_inactive_owner_cannot_refresh(): void
    {
        $staff = User::factory()->create(['role' => 'sales']);
        [, $parameters] = $this->clientParameters();
        $this->actingAs($staff, 'web')->post('/mcp/oauth/authorize', $parameters + ['approved' => 1, 'subscription_audit' => 1])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $pair = $this->oauthPair($admin, true);
        $admin->forceFill(['status' => 'inactive'])->save();
        $this->postJson('/api/mcp/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $pair['client_id'], 'refresh_token' => $pair['refresh_token']])->assertForbidden();
    }

    private function clientParameters(): array
    {
        $client = McpOAuthClient::query()->create(['client_id' => (string) \Illuminate\Support\Str::uuid(), 'client_name' => 'Claude or ChatGPT', 'redirect_uris' => ['https://connector.example.test/callback'], 'active' => true]);
        $verifier = str_repeat('x', 43);

        return [$client, ['response_type' => 'code', 'client_id' => $client->client_id, 'redirect_uri' => 'https://connector.example.test/callback', 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'code_challenge_method' => 'S256', 'scope' => 'mcp:read', 'state' => 'audit-review']];
    }

    private function oauthPair(User $user, bool $audit, ?McpOAuthClient $client = null, ?array $parameters = null): array
    {
        if ($client === null || $parameters === null) {
            [$client, $parameters] = $this->clientParameters();
        }
        $redirect = $this->actingAs($user, 'web')->post('/mcp/oauth/authorize', $parameters + ['approved' => 1, 'subscription_audit' => $audit ? 1 : 0])->assertRedirect();
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $pair = $this->postJson('/api/mcp/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => $client->client_id, 'code' => $query['code'], 'redirect_uri' => $parameters['redirect_uri'], 'code_verifier' => str_repeat('x', 43)])->assertOk()->json();

        return $pair + ['client_id' => $client->client_id];
    }
}
