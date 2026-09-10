<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VIEWS = [
        'vw_client_lifecycle',
        'vw_subscription_deals',
        'vw_churn_events',
        'vw_market_coverage',
        'vw_mcp_lifecycle_rollup',
        'vw_mcp_revenue_rollup',
    ];

    public function up(): void
    {
        $this->dropViews();

        if (Schema::hasTable('clients')) {
            DB::statement('CREATE VIEW vw_client_lifecycle AS
                SELECT
                    clients.id AS client_id,
                    clients.platform_id AS platform_id,
                    clients.lifecycle_state AS lifecycle_state,
                    clients.profile_status AS profile_status,
                    DATE(clients.created_at) AS created_date,
                    '.$this->column('clients', 'first_activated_at', 'first_activated_date').',
                    '.$this->column('clients', 'churned_at', 'churned_date').',
                    '.$this->column('clients', 'churn_reason_code', 'churn_reason_code').'
                FROM clients');

            DB::statement('CREATE VIEW vw_market_coverage AS
                SELECT
                    clients.platform_id AS platform_id,
                    DATE(clients.created_at) AS coverage_date,
                    COUNT(*) AS client_count,
                    SUM(CASE WHEN clients.lifecycle_state = \'active\' THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN clients.lifecycle_state = \'expired\' THEN 1 ELSE 0 END) AS expired_count,
                    SUM(CASE WHEN clients.lifecycle_state = \'archived\' THEN 1 ELSE 0 END) AS archived_count
                FROM clients
                GROUP BY clients.platform_id, DATE(clients.created_at)');

            DB::statement('CREATE VIEW vw_mcp_lifecycle_rollup AS
                SELECT
                    clients.platform_id AS platform_id,
                    DATE(clients.created_at) AS event_date,
                    COALESCE(clients.lifecycle_state, \'unknown\') AS lifecycle_state,
                    COUNT(*) AS entries
                FROM clients
                GROUP BY clients.platform_id, DATE(clients.created_at), COALESCE(clients.lifecycle_state, \'unknown\')');
        }

        if (Schema::hasTable('deals')) {
            DB::statement('CREATE VIEW vw_subscription_deals AS
                SELECT
                    deals.id AS deal_id,
                    deals.client_id AS client_id,
                    deals.platform_id AS platform_id,
                    deals.plan_type AS plan_type,
                    deals.status AS status,
                    deals.amount AS amount,
                    deals.currency AS currency,
                    deals.activated_at AS activated_at,
                    deals.expires_at AS expires_at,
                    '.$this->column('deals', 'subscription_lifecycle', 'subscription_lifecycle').'
                FROM deals');
        }

        if (Schema::hasTable('clients')) {
            DB::statement('CREATE VIEW vw_churn_events AS
                SELECT
                    clients.id AS client_id,
                    clients.platform_id AS platform_id,
                    clients.churned_at AS event_date,
                    clients.churn_reason_code AS reason_code,
                    '.$this->column('clients', 'churn_source', 'source').'
                FROM clients
                WHERE clients.churned_at IS NOT NULL');
        }

        if (Schema::hasTable('payments') && Schema::hasTable('platforms')) {
            DB::statement('CREATE VIEW vw_mcp_revenue_rollup AS
                SELECT
                    payments.platform_id AS platform_id,
                    DATE(COALESCE(payments.completed_at, payments.created_at)) AS revenue_date,
                    SUM('.$this->usdAmountExpression().') AS revenue_usd,
                    COUNT(*) AS entries
                FROM payments
                LEFT JOIN platforms ON platforms.id = payments.platform_id
                WHERE '.$this->reportableWhere().'
                GROUP BY payments.platform_id, DATE(COALESCE(payments.completed_at, payments.created_at))');
        }
    }

    public function down(): void
    {
        $this->dropViews();
    }

    private function dropViews(): void
    {
        foreach (self::VIEWS as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }

    private function column(string $table, string $column, string $alias): string
    {
        return Schema::hasColumn($table, $column)
            ? "{$table}.{$column} AS {$alias}"
            : "NULL AS {$alias}";
    }

    private function reportableWhere(): string
    {
        $clauses = ["payments.status IN ('completed', 'expired')"];

        foreach ([
            'purpose' => "(payments.purpose IS NULL OR payments.purpose NOT IN ('wallet_topup', 'visitor_contact_unlock'))",
            'record_classification' => "(payments.record_classification IS NULL OR payments.record_classification <> 'test')",
            'provider_environment' => "(payments.provider_environment IS NULL OR LOWER(payments.provider_environment) <> 'sandbox')",
            'reconciliation_state' => "(payments.reconciliation_state IS NULL OR payments.reconciliation_state <> 'manual_review')",
            'resolution_code' => "(payments.resolution_code IS NULL OR payments.resolution_code NOT IN ('reversed', 'invalid_reference'))",
        ] as $column => $clause) {
            if (Schema::hasColumn('payments', $column)) {
                $clauses[] = $clause;
            }
        }

        return implode(' AND ', $clauses);
    }

    private function usdAmountExpression(): string
    {
        if (! Schema::hasTable('reporting_fx_rates')) {
            return 'payments.amount';
        }

        return "payments.amount * COALESCE((
            SELECT fx.rate
            FROM reporting_fx_rates fx
            WHERE fx.target_currency = 'USD'
              AND fx.source_currency = UPPER(COALESCE(payments.currency, 'USD'))
              AND fx.rate_date <= DATE(COALESCE(payments.completed_at, payments.created_at))
            ORDER BY fx.rate_date DESC
            LIMIT 1
        ), 1.0)";
    }
};
