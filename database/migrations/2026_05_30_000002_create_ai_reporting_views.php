<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only reporting views for the "Talk to Your Data" (NL->SQL) feature.
 *
 * Every view:
 *  - exposes `platform_id` so the API can inject server-side market scoping,
 *  - bakes a USD approximation via reporting_fx_rates (latest rate on/before the
 *    payment date; falls back to 1.0 when no rate row exists),
 *  - exposes ZERO PII (no client names/phones/emails, no staff names) — only ids,
 *    market/brand names, roles, currencies, amounts, counts and dates.
 *
 * Views are driver-portable (created on both SQLite for tests and MySQL for prod).
 * They intentionally approximate Payment::reportableSuccessful() using only
 * portable column predicates (status/classification/environment/reconciliation),
 * skipping the JSON test_mode flag and manual-bundle guard which are not portable
 * in a view; the dashboard/briefing parity path uses ReportingCurrencyService
 * directly via MetricsSnapshotService rather than these views.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['vw_agent_perf', 'vw_market_revenue', 'vw_payments_usd'] as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }

        $reportableWhere = $this->reportableWhere();
        $usdAmount       = $this->usdAmountExpression();
        $eventDate       = $this->eventDateExpression();

        DB::statement("
            CREATE VIEW vw_payments_usd AS
            SELECT
                payments.id              AS payment_id,
                payments.platform_id     AS platform_id,
                platforms.country        AS market_country,
                UPPER(COALESCE(payments.currency, platforms.currency_code, 'USD')) AS source_currency,
                payments.amount          AS amount_original,
                {$usdAmount}             AS amount_usd,
                payments.status          AS status,
                {$eventDate}             AS payment_date
            FROM payments
            LEFT JOIN platforms ON platforms.id = payments.platform_id
            WHERE {$reportableWhere}
        ");

        DB::statement("
            CREATE VIEW vw_market_revenue AS
            SELECT
                payments.platform_id     AS platform_id,
                platforms.name           AS market_name,
                platforms.country        AS market_country,
                {$eventDate}             AS revenue_date,
                SUM({$usdAmount})        AS revenue_usd,
                COUNT(*)                 AS payments_count
            FROM payments
            LEFT JOIN platforms ON platforms.id = payments.platform_id
            WHERE {$reportableWhere}
            GROUP BY payments.platform_id, platforms.name, platforms.country, {$eventDate}
        ");

        DB::statement("
            CREATE VIEW vw_agent_perf AS
            SELECT
                deals.assigned_to        AS agent_id,
                users.role               AS agent_role,
                payments.platform_id     AS platform_id,
                {$eventDate}             AS revenue_date,
                SUM({$usdAmount})        AS revenue_usd,
                COUNT(*)                 AS payments_count
            FROM payments
            INNER JOIN deals ON deals.id = payments.deal_id AND deals.assigned_to IS NOT NULL
            INNER JOIN users ON users.id = deals.assigned_to
            LEFT JOIN platforms ON platforms.id = payments.platform_id
            WHERE {$reportableWhere}
            GROUP BY deals.assigned_to, users.role, payments.platform_id, {$eventDate}
        ");
    }

    public function down(): void
    {
        foreach (['vw_agent_perf', 'vw_market_revenue', 'vw_payments_usd'] as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }
    }

    /**
     * Portable subset of Payment::reportableSuccessful() / businessVisible() /
     * excludingWalletTopups(), built from columns that actually exist.
     */
    private function reportableWhere(): string
    {
        $clauses = ["payments.status IN ('completed', 'expired')"];

        if (Schema::hasColumn('payments', 'purpose')) {
            $clauses[] = "(payments.purpose IS NULL OR payments.purpose <> 'wallet_topup')";
        }
        if (Schema::hasColumn('payments', 'record_classification')) {
            $clauses[] = "(payments.record_classification IS NULL OR payments.record_classification <> 'test')";
        }
        if (Schema::hasColumn('payments', 'provider_environment')) {
            $clauses[] = "(payments.provider_environment IS NULL OR LOWER(payments.provider_environment) <> 'sandbox')";
        }
        if (Schema::hasColumn('payments', 'reconciliation_state')) {
            $clauses[] = "(payments.reconciliation_state IS NULL OR payments.reconciliation_state <> 'manual_review')";
        }
        if (Schema::hasColumn('payments', 'resolution_code')) {
            $clauses[] = "(payments.resolution_code IS NULL OR payments.resolution_code NOT IN ('reversed', 'invalid_reference'))";
        }

        return implode(' AND ', $clauses);
    }

    private function eventDateExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "date(COALESCE(payments.completed_at, payments.created_at))"
            : "DATE(COALESCE(payments.completed_at, payments.created_at))";
    }

    /**
     * amount * latest USD rate on/before the payment date, falling back to 1.0.
     */
    private function usdAmountExpression(): string
    {
        $eventDate = $this->eventDateExpression();

        return "payments.amount * COALESCE((
            SELECT fx.rate
            FROM reporting_fx_rates fx
            WHERE fx.target_currency = 'USD'
              AND fx.source_currency = UPPER(COALESCE(payments.currency, platforms.currency_code, 'USD'))
              AND fx.rate_date <= {$eventDate}
            ORDER BY fx.rate_date DESC
            LIMIT 1
        ), 1.0)";
    }
};
