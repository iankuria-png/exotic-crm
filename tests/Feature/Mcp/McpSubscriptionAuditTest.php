<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Services\Mcp\McpAuthorizationContext;
use App\Services\Mcp\McpServer;
use App\Services\SubscriptionAudit\SubscriptionAuditException;
use App\Services\SubscriptionAudit\SubscriptionAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class McpSubscriptionAuditTest extends TestCase
{
    use RefreshDatabase;

    private const KINDS = ['summary', 'events', 'trace', 'coverage'];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
    }

    public function test_chatgpt_and_claude_adapters_list_and_read_identical_audit_contracts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = Mockery::mock(SubscriptionAuditService::class);
        foreach (self::KINDS as $kind) {
            $service->shouldReceive('report')->with($kind, Mockery::type('array'), Mockery::on(fn ($auth) => $auth instanceof McpAuthorizationContext && $auth->user->is($admin)))
                ->times(4)->andReturn($this->reportData());
        }
        $this->app->instance(SubscriptionAuditService::class, $service);

        foreach (['2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28'] as $version) {
            $token = $this->auditToken($admin);
            $this->rpc($token, $version, 'initialize', ['protocolVersion' => $version])
                ->assertOk()->assertJsonPath('result.protocolVersion', $version);
            $listed = $this->rpc($token, $version, 'tools/list')->assertOk()->json('result.tools');
            foreach (self::KINDS as $kind) {
                $tool = collect($listed)->firstWhere('name', 'exotic_subscription_audit_'.$kind);
                $this->assertNotNull($tool);
                $this->assertSame('object', $tool['inputSchema']['type']);
                $this->assertContains('purpose', $tool['inputSchema']['required']);
                $arguments = ['purpose' => 'Finance control review'];
                if ($kind === 'trace') {
                    $arguments['event_ref'] = 'evt_'.str_repeat('a', 64);
                }
                $response = $this->rpc($token, $version, 'tools/call', ['name' => $tool['name'], 'arguments' => $arguments])
                    ->assertOk()->assertJsonPath('result.structuredContent.meta.result_state', 'partial')
                    ->assertJsonPath('result.structuredContent.meta.coverage.0.state', 'partial')
                    ->assertJsonPath('result.structuredContent.meta.caveats.0', 'CRM evidence only.')
                    ->assertJsonPath('result.structuredContent.meta.row_count', 1)
                    ->assertJsonPath('result.structuredContent.data.rows.0.event_ref', 'evt_'.str_repeat('a', 64));
                $this->assertSame($response->json('result.structuredContent'), json_decode($response->json('result.content.0.text'), true));
                $this->assertStringNotContainsString('_report_meta', $response->getContent());
            }
        }
    }

    public function test_existing_legacy_allow_all_and_half_grants_do_not_gain_audit_access(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach ([['mcp:read'], ['mcp:read', 'mcp:tool:exotic_subscription_audit_summary'], ['mcp:read', 'mcp:subscription-audit']] as $abilities) {
            $token = $admin->createToken('mcp:existing', $abilities)->plainTextToken;
            $listed = $this->rpc($token, '2025-06-18', 'tools/list')->assertOk()->json('result.tools');
            $this->assertFalse(collect($listed)->contains(fn ($row) => str_starts_with($row['name'], 'exotic_subscription_audit_')));
            $this->rpc($token, '2025-06-18', 'tools/call', ['name' => 'exotic_subscription_audit_summary', 'arguments' => ['purpose' => 'Finance audit']])->assertNotFound();
        }
    }

    public function test_non_admin_owner_cannot_use_a_forged_audit_capability(): void
    {
        $staff = User::factory()->create(['role' => 'sales']);
        $this->rpc($this->auditToken($staff), '2026-07-28', 'tools/call', ['name' => 'exotic_subscription_audit_summary', 'arguments' => ['purpose' => 'Finance audit']])
            ->assertNotFound();
    }

    public function test_legacy_and_enhanced_calls_validate_required_purpose_and_argument_types(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['2025-03-26', '2026-07-28'] as $version) {
            $token = $this->auditToken($admin);
            foreach ([[], ['purpose' => 'ok'], ['purpose' => 'Finance audit', 'page_size' => 101], ['purpose' => 'Finance audit', 'exceptions_only' => 'yes'], ['purpose' => 'Finance audit', 'unknown' => 1]] as $arguments) {
                $this->rpc($token, $version, 'tools/call', ['name' => 'exotic_subscription_audit_events', 'arguments' => $arguments])
                    ->assertStatus(422)->assertJsonPath('error.code', -32602);
            }
        }
    }

    public function test_domain_unavailability_maps_to_safe_mcp_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = Mockery::mock(SubscriptionAuditService::class);
        $service->shouldReceive('report')->once()->andThrow(new SubscriptionAuditException('No completed subscription audit is available.', 'audit_unavailable', 503, -32004));
        $this->app->instance(SubscriptionAuditService::class, $service);
        $this->rpc($this->auditToken($admin), '2026-07-28', 'tools/call', ['name' => 'exotic_subscription_audit_summary', 'arguments' => ['purpose' => 'Finance audit']])
            ->assertStatus(503)->assertJsonPath('error.code', -32004)->assertJsonPath('error.message', 'No completed subscription audit is available.');
    }

    public function test_preview_requires_a_real_active_owned_grant(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $this->auditToken($otherAdmin);
        $service = Mockery::mock(SubscriptionAuditService::class);
        $service->shouldReceive('report')->once()->andReturn($this->reportData());
        $this->app->instance(SubscriptionAuditService::class, $service);
        $server = app(McpServer::class);
        $request = Request::create('/api/crm/settings/mcp/preview', 'POST');
        try {
            $server->previewTool($request, 'exotic_subscription_audit_summary', ['purpose' => 'Finance review'], $admin);
            $this->fail('Synthetic preview abilities must not confer audit access.');
        } catch (\App\Services\Mcp\McpProtocolException $error) {
            $this->assertSame(403, $error->httpStatus);
        }
        $this->auditToken($admin);
        $preview = $server->previewTool($request, 'exotic_subscription_audit_summary', ['purpose' => 'Finance review'], $admin);
        $this->assertSame('partial', $preview['payload']['meta']['result_state']);
    }

    public function test_final_sanitizer_still_refuses_raw_customer_fields(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $service = Mockery::mock(SubscriptionAuditService::class);
        $service->shouldReceive('report')->once()->andReturn(['rows' => [['phone' => '254700000001']]]);
        $this->app->instance(SubscriptionAuditService::class, $service);
        $response = $this->rpc($this->auditToken($admin), '2026-07-28', 'tools/call', ['name' => 'exotic_subscription_audit_events', 'arguments' => ['purpose' => 'Finance audit']])
            ->assertStatus(500)->assertJsonPath('error.code', -32603);
        $this->assertStringNotContainsString('254700000001', $response->getContent());
    }

    private function auditToken(User $user): string
    {
        return $user->createToken('mcp:audit-test', array_merge(['mcp:read', 'mcp:subscription-audit'], array_map(fn ($kind) => 'mcp:tool:exotic_subscription_audit_'.$kind, self::KINDS)), now()->addDay())->plainTextToken;
    }

    private function reportData(): array
    {
        return ['rows' => [['event_ref' => 'evt_'.str_repeat('a', 64)]], '_report_meta' => ['result_state' => 'partial', 'coverage' => [['state' => 'partial']], 'caveats' => ['CRM evidence only.'], 'row_count' => 1, 'filters' => ['purpose_recorded' => true]]];
    }

    private function rpc(string $token, string $version, string $method, array $params = [])
    {
        if ($version === '2026-07-28') {
            $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => $version];
        }

        return $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => random_int(1, 999999), 'method' => $method, 'params' => $params], ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => $version]);
    }
}
