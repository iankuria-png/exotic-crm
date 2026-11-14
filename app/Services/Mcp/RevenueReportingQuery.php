<?php

namespace App\Services\Mcp;

use App\Models\Payment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One eligibility definition for MCP totals and its aggregate-only SQL view. */
final class RevenueReportingQuery
{
    public const VIEW = 'vw_mcp_payment_collections';

    public function candidates(): Builder
    {
        $json = function (string $column, string $path): string {
            $extract = "JSON_EXTRACT($column, '$.$path')";

            return DB::connection()->getDriverName() === 'mysql' ? "NULLIF(JSON_UNQUOTE($extract), 'null')" : $extract;
        };
        $account = $json('payments.payment_data', 'collection.account_id');
        $method = $json('payments.raw_payload', 'method');
        $providerAccount = Schema::hasTable('billing_routing_decisions')
            ? '(SELECT provider_profile_id FROM billing_routing_decisions WHERE payment_id = payments.id AND provider_profile_id IS NOT NULL ORDER BY id DESC LIMIT 1)'
            : 'NULL';
        $concat = fn (string $prefix, string $expression) => DB::connection()->getDriverName() === 'mysql'
            ? "CONCAT('$prefix', $expression)" : "('$prefix' || $expression)";
        $accountKey = 'COALESCE('.$concat('collection:', $account).', '.$concat('profile:', $providerAccount).')';
        $wallet = "(LOWER(COALESCE(payments.source, '')) IN ('wallet','crm_wallet') OR LOWER(COALESCE($method, '')) = 'wallet' OR payments.wallet_transaction_id IS NOT NULL)";
        $funding = "CASE WHEN $wallet THEN 'wallet_redemption' WHEN LOWER(COALESCE(payments.source, '')) IN ('gateway','manual','manual_confirmation','mpesa','pawapay','bank','import') OR NULLIF(payments.provider_key, '') IS NOT NULL THEN 'new_cash' ELSE 'unknown' END";
        $reference = "UPPER(TRIM(COALESCE(NULLIF(payments.reference_root, ''), NULLIF(payments.transaction_reference, ''), NULLIF(payments.reference_number, ''))))";
        $receipt = 'CASE WHEN payments.wallet_transaction_id IS NOT NULL THEN '.$concat('wallet:', 'payments.wallet_transaction_id')." ELSE $reference END";
        $identity = 'CASE WHEN payments.manual_payment_bundle_id IS NOT NULL THEN '.$concat('bundle:', 'payments.manual_payment_bundle_id').' ELSE '.$concat('payment:', 'payments.id').' END';
        $category = "CASE WHEN payments.purpose IS NULL OR payments.purpose = 'subscription' THEN CASE WHEN payments.subscription_lifecycle = 'renewal' THEN 'renewal' ELSE 'subscription' END WHEN payments.purpose = 'visitor_contact_unlock' THEN 'contact_unlock' WHEN payments.purpose IN ('premium_content_sale','send_love','tour_pass','monetize_pass') THEN payments.purpose ELSE 'other_paid_service' END";

        return Payment::query()->reportableSuccessful()
            ->where('payments.amount', '>', 0)
            ->where(fn ($q) => $q->whereNull('payments.resolution_code')->orWhereNotIn('payments.resolution_code', ['refunded', 'refund', 'chargeback', 'duplicate']))
            ->where(fn ($q) => $q->whereNull('payments.purpose')->orWhere('payments.purpose', '!=', Payment::PURPOSE_WALLET_TOPUP))
            ->whereNotIn(DB::raw("LOWER(COALESCE(payments.source, ''))"), ['free_trial', 'complimentary'])
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->selectRaw('payments.id, payments.platform_id, payments.amount, payments.manual_payment_bundle_id, payments.reference_sequence')
            ->selectRaw('COALESCE(payments.completed_at, payments.confirmed_at, payments.created_at) AS occurred_at')
            ->selectRaw("CASE WHEN payments.completed_at IS NOT NULL THEN 'completed_at' WHEN payments.confirmed_at IS NOT NULL THEN 'confirmed_at' ELSE 'created_at' END AS clock_source")
            ->selectRaw("UPPER(COALESCE(NULLIF(payments.currency, ''), NULLIF(platforms.currency_code, ''), 'UNKNOWN')) AS currency")
            ->selectRaw("COALESCE(NULLIF(platforms.country, ''), 'Unassigned') AS country")
            ->selectRaw("$category AS category, $funding AS funding, $receipt AS receipt_key, $identity AS receipt_identity, $accountKey AS account_key")
            ->selectRaw("LOWER(COALESCE(NULLIF(payments.provider_key, ''), NULLIF(payments.source, ''), 'unknown')) AS provider_scope")
            ->selectRaw("CASE WHEN LOWER(COALESCE(payments.provider_environment, '')) IN ('live','production') THEN 'live' ELSE LOWER(COALESCE(payments.provider_environment, 'unknown')) END AS environment_scope")
            ->toBase();
    }

    public function rows(): Builder
    {
        // Search all retained eligible records before window/platform filtering.
        // A repeat just outside the requested interval must still be detected.
        $collision = DB::query()->fromSub($this->candidates(), 'other_receipt')->selectRaw('1')
            ->whereColumn('other_receipt.id', '!=', 'reported.id')
            ->whereNotNull('reported.receipt_key')->where('reported.receipt_key', '!=', '')
            ->whereColumn('other_receipt.receipt_key', 'reported.receipt_key')
            ->whereColumn('other_receipt.currency', 'reported.currency')
            ->whereColumn('other_receipt.provider_scope', 'reported.provider_scope')
            ->whereColumn('other_receipt.environment_scope', 'reported.environment_scope')
            ->where(function ($q) {
                $q->where(function ($known) {
                    $known->whereNotNull('reported.account_key')->whereColumn('other_receipt.account_key', 'reported.account_key');
                })->orWhere(function ($legacy) {
                    $legacy->whereColumn('other_receipt.platform_id', 'reported.platform_id')
                        ->where(fn ($missing) => $missing->whereNull('reported.account_key')->orWhereNull('other_receipt.account_key'));
                });
            })
            ->where(function ($q) {
                $q->whereColumn('other_receipt.receipt_identity', '!=', 'reported.receipt_identity')
                    ->orWhere(function ($allocation) {
                        $allocation->whereNotNull('reported.manual_payment_bundle_id')
                            ->where(fn ($seq) => $seq->whereColumn('other_receipt.reference_sequence', 'reported.reference_sequence')
                                ->orWhereNull('reported.reference_sequence')->orWhereNull('other_receipt.reference_sequence'));
                    });
            });

        return DB::query()->fromSub($this->candidates(), 'reported')->select('reported.*')
            ->selectRaw('CASE WHEN EXISTS ('.$collision->toSql().") THEN 'ambiguous_receipt' ELSE 'included' END AS disposition", $collision->getBindings());
    }

    public function aggregateView(): Builder
    {
        return DB::query()->fromSub($this->rows(), 'collections')->where('disposition', 'included')
            ->select(['platform_id', 'country', 'occurred_at', 'clock_source', 'currency', 'category', 'funding', 'disposition'])
            ->selectRaw("SUM(CASE WHEN funding = 'new_cash' THEN amount ELSE 0 END) AS amount, SUM(CASE WHEN funding = 'new_cash' THEN 1 ELSE 0 END) AS payments_count, SUM(amount) AS earned_service_amount, COUNT(*) AS service_payment_count")
            ->groupBy(['platform_id', 'country', 'occurred_at', 'clock_source', 'currency', 'category', 'funding', 'disposition']);
    }
}
