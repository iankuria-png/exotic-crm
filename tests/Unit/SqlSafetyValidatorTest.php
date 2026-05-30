<?php

namespace Tests\Unit;

use App\Services\Ai\Exceptions\SqlValidationException;
use App\Services\Ai\SqlSafetyValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SqlSafetyValidatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.insights.enabled' => true,
            'ai.insights.default_row_limit' => 25,
            'ai.insights.max_row_limit' => 100,
            'ai.reporting_views' => ['vw_payments_usd', 'vw_market_revenue', 'vw_agent_perf'],
        ]);
    }

    public function test_valid_select_without_limit_gets_bounded_limit(): void
    {
        $validated = app(SqlSafetyValidator::class)->validate(
            'SELECT platform_id, market_name, revenue_usd FROM vw_market_revenue'
        );

        $this->assertSame('SELECT platform_id, market_name, revenue_usd FROM vw_market_revenue LIMIT 25', $validated['sql']);
        $this->assertSame(25, $validated['limit']);
        $this->assertSame(['vw_market_revenue'], $validated['views']);
        $this->assertFalse($validated['scoped']);
    }

    public function test_existing_limit_is_clamped_to_max(): void
    {
        $validated = app(SqlSafetyValidator::class)->validate(
            'SELECT platform_id, market_name, revenue_usd FROM vw_market_revenue LIMIT 5000'
        );

        $this->assertStringEndsWith('LIMIT 100', $validated['sql']);
        $this->assertSame(100, $validated['limit']);
    }

    public function test_sub_admin_scope_is_injected_server_side(): void
    {
        $validated = app(SqlSafetyValidator::class)->validate(
            'SELECT platform_id, market_name, revenue_usd FROM vw_market_revenue LIMIT 50',
            [9, 3, 3]
        );

        $this->assertSame(
            'SELECT * FROM (SELECT platform_id, market_name, revenue_usd FROM vw_market_revenue) AS scoped_q WHERE scoped_q.platform_id IN (9, 3) LIMIT 50',
            $validated['sql']
        );
        $this->assertTrue($validated['scoped']);
    }

    public function test_empty_sub_admin_scope_matches_no_rows(): void
    {
        $validated = app(SqlSafetyValidator::class)->validate(
            'SELECT platform_id, market_name, revenue_usd FROM vw_market_revenue LIMIT 10',
            []
        );

        $this->assertStringContainsString('WHERE 0 = 1 LIMIT 10', $validated['sql']);
    }

    public function test_scoped_query_must_project_platform_id(): void
    {
        $this->assertValidationReason('missing_platform_id', fn () => app(SqlSafetyValidator::class)->validate(
            'SELECT market_name, revenue_usd FROM vw_market_revenue LIMIT 10',
            [1]
        ));
    }

    public function test_base_tables_are_rejected(): void
    {
        $this->assertValidationReason(
            'table_not_allowed',
            fn () => app(SqlSafetyValidator::class)->validate('SELECT platform_id, amount FROM payments LIMIT 10')
        );
    }

    public function test_write_keywords_are_rejected(): void
    {
        $this->assertValidationReason(
            'forbidden_keyword',
            fn () => app(SqlSafetyValidator::class)->validate('SELECT platform_id FROM vw_market_revenue WHERE drop IS NULL LIMIT 10')
        );
    }

    public function test_comments_are_rejected(): void
    {
        $this->assertValidationReason(
            'comment',
            fn () => app(SqlSafetyValidator::class)->validate('SELECT platform_id FROM vw_market_revenue -- hide this LIMIT 10')
        );
    }

    public function test_stacked_queries_are_rejected(): void
    {
        $this->assertValidationReason(
            'multi_statement',
            fn () => app(SqlSafetyValidator::class)->validate('SELECT platform_id FROM vw_market_revenue LIMIT 10; SELECT * FROM vw_agent_perf LIMIT 10')
        );
    }

    public function test_non_select_is_rejected(): void
    {
        $this->assertValidationReason(
            'not_select',
            fn () => app(SqlSafetyValidator::class)->validate('UPDATE vw_market_revenue SET revenue_usd = 0')
        );
    }

    private function assertValidationReason(string $reason, \Closure $callback): void
    {
        try {
            $callback();
            $this->fail("Expected SQL validation reason [{$reason}].");
        } catch (SqlValidationException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }
}
