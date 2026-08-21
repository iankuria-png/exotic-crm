<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['vw_agent_perf', 'vw_market_revenue', 'vw_payments_usd'] as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }

        $reportableWhere = $this->reportableWhere();
        $sourceCurrency = $this->canonicalCurrencyExpression();
        $usdAmount = $this->usdAmountExpression($sourceCurrency);
        $eventDate = $this->eventDateExpression();

        DB::statement("
            CREATE VIEW vw_payments_usd AS
            SELECT
                payments.id              AS payment_id,
                payments.platform_id     AS platform_id,
                platforms.country        AS market_country,
                {$sourceCurrency}        AS source_currency,
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
            ? 'date(COALESCE(payments.completed_at, payments.created_at))'
            : 'DATE(COALESCE(payments.completed_at, payments.created_at))';
    }

    private function canonicalCurrencyExpression(): string
    {
        $rawCurrency = "UPPER(REPLACE(REPLACE(REPLACE(TRIM(COALESCE(payments.currency, platforms.currency_code, 'USD')), ' ', ''), ',', ''), '.', ''))";
        $xofMarket = $this->marketMatches([
            'Benin',
            'Benin Republic',
            'Burkina Faso',
            "Cote d'Ivoire",
            "Côte d'Ivoire",
            'Guinea-Bissau',
            'Guinea Bissau',
            'Ivory Coast',
            'Mali',
            'Niger',
            'Senegal',
            'Togo',
            'BEN',
            'BFA',
            'CIV',
            'GNB',
            'MLI',
            'NER',
            'SEN',
            'TGO',
        ]);
        $xafMarket = $this->marketMatches([
            'Cameroon',
            'Central African Republic',
            'Chad',
            'Congo',
            'Equatorial Guinea',
            'Gabon',
            'Republic of the Congo',
            'CMR',
            'CAF',
            'TCD',
            'COG',
            'GNQ',
            'GAB',
        ]);

        return "CASE
            WHEN {$rawCurrency} IN ('KSH', 'KSHS') THEN 'KES'
            WHEN {$rawCurrency} IN ('TSH', 'TSHS') THEN 'TZS'
            WHEN {$rawCurrency} = 'NAIRA' THEN 'NGN'
            WHEN {$rawCurrency} = 'CEDI' THEN 'GHS'
            WHEN {$rawCurrency} IN ('BIR', 'BIRR') THEN 'ETB'
            WHEN {$rawCurrency} IN ('USD$', '$') THEN 'USD'
            WHEN {$rawCurrency} IN ('CFA', 'FCFA') AND {$xofMarket} THEN 'XOF'
            WHEN {$rawCurrency} IN ('CFA', 'FCFA') AND {$xafMarket} THEN 'XAF'
            ELSE {$rawCurrency}
        END";
    }

    private function usdAmountExpression(string $sourceCurrency): string
    {
        $eventDate = $this->eventDateExpression();
        $rateDate = DB::connection()->getDriverName() === 'sqlite' ? 'date(fx.rate_date)' : 'fx.rate_date';

        return "payments.amount * COALESCE((
            SELECT fx.rate
            FROM reporting_fx_rates fx
            WHERE fx.target_currency = 'USD'
              AND fx.source_currency = {$sourceCurrency}
              AND {$rateDate} <= {$eventDate}
            ORDER BY fx.rate_date DESC
            LIMIT 1
        ), 1.0)";
    }

    /**
     * @param  string[]  $values
     */
    private function marketMatches(array $values): string
    {
        $quoted = collect($values)
            ->map(fn (string $value) => "'".str_replace("'", "''", $value)."'")
            ->implode(', ');

        return "(COALESCE(platforms.country, '') IN ({$quoted}) OR COALESCE(platforms.name, '') IN ({$quoted}))";
    }
};
