<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionReceipt;
use Illuminate\Support\Facades\DB;

class CollectionGate
{
    public function assertFunds(CollectionReceipt $r): void
    {
        if ($r->historical_only) {
            throw new CollectionException('historical_receipt_no_dispatch');
        }
        if ($r->conflicts || ! in_array($r->funds_state, ['observed', 'statement_verified']) || $r->principal_minor <= 0 || ! $r->provider_at) {
            throw new CollectionException('receipt_requires_finance_review');
        }
        $a = $r->account;
        if (! $a->verified_at || $a->suspended_at || $r->currency !== $a->currency) {
            throw new CollectionException('receiving_account_not_ready');
        }
    }

    public function assertWrites(CollectionAccount $account, array $platformIds, bool $automatic, bool $correction = false, ?string $ownAllocation = null): void
    {
        $parserVersion = config('collection.parser_versions.'.$account->provider_key);
        if (! in_array($account->mode, ['assisted', 'auto']) || $account->suspended_at || ! $account->verified_at) {
            throw new CollectionException('account_entitlement_writes_disabled');
        }
        foreach ($platformIds as $platformId) {
            if (! in_array((int) $platformId, config('collection.entitlement_platforms'), true)
                || ! in_array((int) $platformId, config('collection.verified_writer_platforms'), true)
                || ($correction && ! in_array((int) $platformId, config('collection.verified_correction_platforms'), true))) {
                throw new CollectionException('exact_site_writer_acceptance_required');
            }
        }
        if ($automatic && ($account->mode !== 'auto' || ! in_array($account->id, config('collection.automatic_accounts'), true)
            || data_get($account->policy, 'clean_observation_days', 0) < 7 || data_get($account->policy, 'positive_receiving_credits', 0) < 100
            || ! data_get($account->policy, 'independent_reconciliation_accepted_at') || ! data_get($account->policy, 'held_out_parser_accepted_at')
            || ! data_get($account->policy, 'correction_acceptance_at') || ! data_get($account->policy, 'device_capture_accepted_at')
            || ! data_get($account->policy, 'settlement_policy_accepted_at') || ! data_get($account->policy, 'historical_reference_map_accepted_at')
            || ! is_string($parserVersion) || ! in_array($parserVersion, data_get($account->policy, 'accepted_parser_versions', []), true))) {
            throw new CollectionException('automatic_account_acceptance_required');
        }
        if ($automatic) {
            $deviceReady = app(CollectionDeviceHealthService::class)->accountReady($account->id);
            if (! $deviceReady) {
                throw new CollectionException('receiving_device_offline_or_unbound');
            }
            $unknown = DB::table('collection_allocations as a')->join('collection_receipts as r', 'r.id', '=', 'a.receipt_id')
                ->where('r.account_id', $account->id)->when($ownAllocation, fn ($q) => $q->where('a.id', '!=', $ownAllocation))->whereIn('a.state', ['uncertain', 'correcting'])->exists();
            if ($unknown) {
                throw new CollectionException('account_has_uncertain_operation');
            }
        }
    }
}
