<?php

namespace Tests\Feature\Mcp;

use App\Models\Briefing;
use App\Models\BriefingRun;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\ContactUnlockPulseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class McpProductionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_weekly_scorecard_projects_raw_archive_identifiers_before_the_mcp_boundary(): void
    {
        $admin = $this->admin();
        $run = BriefingRun::query()->create([
            'audience' => 'ceo',
            'period' => 'weekly',
            'period_start' => now()->subWeek()->startOfWeek(),
            'period_end' => now()->subWeek()->endOfWeek(),
            'dry_run' => false,
            'status' => 'completed',
            'cost_usd' => 0,
        ]);
        Briefing::query()->create([
            'briefing_run_id' => $run->id,
            'audience' => 'ceo',
            'scope_hash' => Briefing::scopeHashFor(null),
            'period' => 'weekly',
            'period_start' => now()->subWeek()->startOfWeek(),
            'period_end' => now()->subWeek()->endOfWeek(),
            'body_full' => json_encode([
                'version' => 'executive_scorecard_v3',
                'scorecards' => [['key' => 'revenue', 'current' => 1250]],
                'watch_items' => [['client_id' => 44, 'client_name' => 'Protected Client', 'phone' => '254700000000']],
            ], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->rpcToolCall($admin, 'exotic_weekly_executive_scorecard')->assertOk();

        $response->assertJsonPath('result.structuredContent.data.weeks.0.scorecard.version', 'executive_scorecard_v3');
        $json = $response->getContent();
        $this->assertStringNotContainsString('client_id', $json);
        $this->assertStringNotContainsString('Protected Client', $json);
        $this->assertStringNotContainsString('254700000000', $json);
    }

    public function test_visitor_demand_projects_top_profiles_to_pseudonymous_handles(): void
    {
        $admin = $this->admin();
        $pulse = Mockery::mock(ContactUnlockPulseService::class);
        $pulse->shouldReceive('summary')->once()->andReturn([
            'kpis' => ['successful_payments' => 1],
            'top_profiles' => [[
                'client_id' => 44,
                'label' => 'Protected Client',
                'count' => 3,
                'amount_normalized' => 25.0,
                'amount_display' => 'USD 25.00',
                'normalized_currency' => 'USD',
                'source_breakdown' => ['USD' => 25.0],
            ]],
        ]);
        $this->app->instance(ContactUnlockPulseService::class, $pulse);

        $response = $this->rpcToolCall($admin, 'exotic_visitor_demand', ['range' => '7d'])->assertOk();

        $response->assertJsonPath('result.structuredContent.data.overview.top_profiles.0.client_handle', app(\App\Services\Mcp\PseudonymService::class)->handle('client', 44));
        $json = $response->getContent();
        $this->assertStringNotContainsString('client_id', $json);
        $this->assertStringNotContainsString('Protected Client', $json);
    }

    public function test_city_performance_builds_scored_rows_without_mutating_a_collection_item(): void
    {
        $admin = $this->admin();
        $platform = Platform::factory()->create();
        Client::factory()->create([
            'platform_id' => $platform->id,
            'city' => 'Nairobi',
            'notactive' => false,
            'verified' => true,
        ]);

        $response = $this->rpcToolCall($admin, 'exotic_city_performance', ['platform_id' => $platform->id])->assertOk();

        $response->assertJsonPath('result.structuredContent.data.cities.0.city', 'Nairobi');
        $this->assertSame('unavailable', $response->json('result.structuredContent.data.cities.0.analytics_status'));
        $this->assertIsArray($response->json('result.structuredContent.data.cities.0.performance'));
    }

    private function admin(): User
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);

        return User::factory()->create(['role' => 'admin']);
    }

    private function rpcToolCall(User $user, string $tool, array $arguments = [])
    {
        $token = $user->createToken('mcp:production-regression', ['mcp:read', 'mcp:tool:'.$tool])->plainTextToken;

        return $this->withToken($token)->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => random_int(1, 99999),
            'method' => 'tools/call',
            'params' => [
                'name' => $tool,
                'arguments' => $arguments,
                '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'],
            ],
        ], [
            'Accept' => 'application/json, text/event-stream',
            'Content-Type' => 'application/json',
            'MCP-Protocol-Version' => '2026-07-28',
        ]);
    }
}
