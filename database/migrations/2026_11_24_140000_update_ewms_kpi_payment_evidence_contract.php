<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Schema-independent MariaDB 11.4 canonical definitions, verified against
    // production (original) and the isolated 11.4 migration fixture (current).
    private const ORIGINAL_MARIADB_HASH = '626d676a578435c52a111017fe73392f7b9723a2140f423a609dc54e78ee8e1d';

    private const CURRENT_MARIADB_HASH = '7ca2a45763076b56ef5735e3edc227c1caad8bd84c8f6967ffce8867e8e525c2';

    public function up(): void
    {
        $this->replace($this->currentSql(), false);
    }

    public function down(): void
    {
        $this->replace($this->originalSql(), true);
    }

    private function replace(string $sql, bool $rollback): void
    {
        $connection = DB::connection();
        if ($connection->getTablePrefix() !== '') {
            throw new RuntimeException('EWMS view requires its verified unprefixed schema.');
        }
        $driver = $connection->getDriverName();
        if ($driver === 'sqlite') {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'view' AND name = 'ewms_kpi_payment_evidence'");
            $expected = 'CREATE VIEW ewms_kpi_payment_evidence AS '.($rollback ? $this->currentSql() : $this->originalSql());
            if (! $row || $this->hash($row->sql) !== $this->hash($expected)) {
                throw new RuntimeException('EWMS view definition drift; replacement refused.');
            }
            DB::transaction(function () use ($sql): void {
                DB::statement('DROP VIEW ewms_kpi_payment_evidence');
                DB::statement('CREATE VIEW ewms_kpi_payment_evidence AS '.$sql);
            });
        } elseif ($driver === 'mysql') {
            $row = DB::selectOne('SELECT VIEW_DEFINITION AS definition, DEFINER AS definer, SECURITY_TYPE AS security_type FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['ewms_kpi_payment_evidence']);
            $expected = $rollback ? self::CURRENT_MARIADB_HASH : self::ORIGINAL_MARIADB_HASH;
            if (! $row || $this->hash($row->definition) !== $expected) {
                throw new RuntimeException('EWMS view definition drift; replacement refused.');
            }
            // CREATE OR REPLACE preserves grants. Retain the exact existing
            // definer and security mode, rather than changing effective access.
            $account = explode('@', $row->definer);
            if (count($account) !== 2 || ! in_array($row->security_type, ['DEFINER', 'INVOKER'], true)) {
                throw new RuntimeException('Unsupported existing EWMS view ownership.');
            }
            $quoted = array_map(static fn (string $part): string => '`'.str_replace('`', '``', $part).'`', $account);
            DB::statement('CREATE OR REPLACE DEFINER = '.implode('@', $quoted).' SQL SECURITY '.$row->security_type.' VIEW ewms_kpi_payment_evidence AS '.$sql);
        } else {
            throw new RuntimeException('Unsupported EWMS view migration driver.');
        }
        // Resolve dependencies immediately. MariaDB DDL is not transactional;
        // a failure here requires independent inspection before retry/recovery.
        DB::select('SELECT * FROM ewms_kpi_payment_evidence WHERE 1 = 0');
    }

    private function hash(string $definition): string
    {
        $database = str_replace('`', '``', DB::connection()->getDatabaseName());
        $definition = str_replace('`'.$database.'`.', '', $definition);

        return hash('sha256', preg_replace('/\s+/', '', $definition));
    }

    private function currentSql(): string
    {
        return $this->selectSql(
            'COALESCE(payments.completed_at, payments.created_at)',
            "UPPER(COALESCE(NULLIF(TRIM(payments.currency), ''), NULLIF(TRIM(platforms.currency_code), '')))",
            ' LEFT JOIN platforms ON platforms.id = payments.platform_id',
            [
                "(JSON_EXTRACT(payments.payment_data, '$.test_mode') IS NULL OR JSON_EXTRACT(payments.payment_data, '$.test_mode') = false)",
                "(payments.purpose IS NULL OR payments.purpose NOT IN ('premium_content_sale', 'send_love', 'monetize_pass', 'tour_pass', 'wallet_topup', 'visitor_contact_unlock'))",
                "(NOT EXISTS (SELECT 1 FROM collection_sales_sessions WHERE collection_sales_sessions.bundle_id = payments.manual_payment_bundle_id)
                    OR EXISTS (SELECT 1 FROM collection_sales_items WHERE collection_sales_items.payment_id = payments.id
                        AND collection_sales_items.state = 'confirmed' AND collection_sales_items.verified_at IS NOT NULL))",
            ]
        );
    }

    private function originalSql(): string
    {
        return $this->selectSql('payments.created_at', "UPPER(COALESCE(payments.currency, 'USD'))", '', ["(payments.purpose IS NULL OR payments.purpose <> 'wallet_topup')"]);
    }

    private function selectSql(string $date, string $currency, string $join, array $extra): string
    {
        $where = implode(' AND ', array_merge([
            "(payments.record_classification IS NULL OR payments.record_classification <> 'test')",
            "(payments.provider_environment IS NULL OR LOWER(payments.provider_environment) <> 'sandbox')",
        ], $extra, [
            "(payments.manual_payment_bundle_id IS NULL OR NOT EXISTS (
                SELECT 1 FROM manual_payment_bundles WHERE manual_payment_bundles.id = payments.manual_payment_bundle_id
                    AND manual_payment_bundles.status IN ('committing', 'compensation_failed', 'voided')
            ))",
        ]));

        return "SELECT
            payments.id AS payment_id,
            payments.user_id AS crm_user_id,
            payments.platform_id AS platform_id,
            {$date} AS recorded_at,
            payments.amount AS amount,
            {$currency} AS currency,
            payments.transaction_reference AS transaction_reference,
            payments.status AS status,
            payments.reconciliation_state AS reconciliation_state,
            payments.resolution_code AS resolution_code,
            CASE WHEN payments.status IN ('completed', 'expired')
                AND (payments.reconciliation_state IS NULL OR payments.reconciliation_state <> 'manual_review')
                AND (payments.resolution_code IS NULL OR payments.resolution_code NOT IN ('reversed', 'invalid_reference'))
                THEN 1 ELSE 0 END AS is_reportable_success
            FROM payments{$join} WHERE {$where}";
    }
};
