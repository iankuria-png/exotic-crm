<?php

namespace Tests\Unit\Mcp;

use App\Models\User;
use App\Services\Mcp\McpRequestAdapter;
use App\Services\Mcp\McpResultNormalizer;
use App\Services\Mcp\Presenters\AgentPerformancePresenter;
use PHPUnit\Framework\TestCase;

class McpResultPresentationTest extends TestCase
{
    public function test_revenue_contract_money_is_rounded_without_changing_non_money_metrics(): void
    {
        $payload = (new McpResultNormalizer)->forTool('exotic_revenue_summary', [
            'total' => 13924.819999999999,
            'source_breakdown' => ['KES' => 19.999999999999],
            'rate' => 7.777777777777,
        ]);

        $this->assertSame(13924.82, $payload['total']);
        $this->assertSame(20.0, $payload['source_breakdown']['KES']);
        $this->assertSame(7.777777777777, $payload['rate']);
    }

    public function test_agent_performance_uses_staff_names_without_internal_ids_for_admins(): void
    {
        $admin = new User(['role' => 'admin']);
        $payload = (new AgentPerformancePresenter)->present(['agents' => [['id' => 18, 'name' => 'Jane Agent', 'role' => 'sales']]], $admin);

        $this->assertSame('Jane Agent', $payload['agents'][0]['agent_display_name']);
        $this->assertArrayNotHasKey('id', $payload['agents'][0]);
        $this->assertArrayNotHasKey('name', $payload['agents'][0]);
    }

    public function test_limit_is_forwarded_to_the_dashboard_query(): void
    {
        $request = (new McpRequestAdapter)->build(['limit' => 3], new User);

        $this->assertSame(3, $request->query('limit'));
    }
}
