<?php

namespace Tests\Unit\Mcp;

use App\Services\Ai\Exceptions\SqlValidationException;
use App\Services\Ai\SqlSafetyValidator;
use App\Services\Ai\SqlValidationPolicy;
use Tests\TestCase;

class SqlValidationPolicyTest extends TestCase
{
    public function test_mcp_policy_has_independent_views_limits_and_projection_guard(): void
    {
        $validator = app(SqlSafetyValidator::class);
        $policy = new SqlValidationPolicy(
            allowedViews: ['vw_mcp_revenue_rollup'],
            defaultRowLimit: 7,
            maxRowLimit: 11,
            forbiddenColumns: ['payment_id', 'client_id'],
        );

        $result = $validator->validateWithPolicy(
            'SELECT platform_id, revenue_usd FROM vw_mcp_revenue_rollup',
            null,
            $policy,
        );

        $this->assertSame(7, $result['limit']);
        $this->assertSame(['vw_mcp_revenue_rollup'], $result['views']);
        $this->assertStringEndsWith('LIMIT 7', $result['sql']);

        $this->expectException(SqlValidationException::class);
        $this->expectExceptionMessage('forbidden column: client_id');
        $validator->validateWithPolicy(
            'SELECT client_id FROM vw_mcp_revenue_rollup',
            null,
            $policy,
        );
    }
}
