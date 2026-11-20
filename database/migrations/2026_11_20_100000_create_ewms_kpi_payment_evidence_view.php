<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Narrow payment evidence contract for the EWMS Customer Service KPI pilot.
 * Grant a dedicated principal SELECT on this view only, never on its source tables.
 * Ownership is payments.user_id; no customer or staff identity tables are joined.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fail safely if a view already exists; do not drop another integration's view.
        DB::statement("
            CREATE VIEW ewms_kpi_payment_evidence AS
            SELECT
                payments.id AS payment_id,
                payments.user_id AS crm_user_id,
                payments.platform_id AS platform_id,
                payments.created_at AS recorded_at,
                payments.amount AS amount,
                UPPER(COALESCE(payments.currency, 'USD')) AS currency,
                payments.transaction_reference AS transaction_reference,
                payments.status AS status,
                payments.reconciliation_state AS reconciliation_state,
                payments.resolution_code AS resolution_code,
                CASE
                    WHEN payments.status IN ('completed', 'expired')
                        AND (payments.reconciliation_state IS NULL OR payments.reconciliation_state <> 'manual_review')
                        AND (payments.resolution_code IS NULL OR payments.resolution_code NOT IN ('reversed', 'invalid_reference'))
                    THEN 1
                    ELSE 0
                END AS is_reportable_success
            FROM payments
            WHERE {$this->businessVisibleWhere()}
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS ewms_kpi_payment_evidence');
    }

    /**
     * Preserve the requested portable exclusions, without coupling this historical
     * migration to future changes in Payment::businessVisible(). All statuses remain
     * candidates; the success flag does not represent HOD verification or approval.
     */
    private function businessVisibleWhere(): string
    {
        return implode(' AND ', [
            "(payments.record_classification IS NULL OR payments.record_classification <> 'test')",
            "(payments.provider_environment IS NULL OR LOWER(payments.provider_environment) <> 'sandbox')",
            "(payments.purpose IS NULL OR payments.purpose <> 'wallet_topup')",
            "(payments.manual_payment_bundle_id IS NULL OR NOT EXISTS (
                SELECT 1
                FROM manual_payment_bundles
                WHERE manual_payment_bundles.id = payments.manual_payment_bundle_id
                  AND manual_payment_bundles.status IN ('committing', 'compensation_failed', 'voided')
            ))",
        ]);
    }
};
