<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionReceipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Immutable intake eligibility; changing policy never makes queued receipts new. */
class CollectionAutomationEpoch
{
    public function intake(CollectionAccount $account, bool $live): array
    {
        $p = $account->policy ?? [];
        $eligible = $live && $account->mode === 'auto' && ! $account->suspended_at && $account->verified_at
            && ($p['automation_enabled'] ?? false) === true && Str::isUuid($p['automation_epoch'] ?? '')
            && ! empty($p['enabled_from']) && $this->validAge($p['max_auto_age_seconds'] ?? null);

        return ['automation_epoch' => $eligible ? $p['automation_epoch'] : null,
            'automation_eligible_at_ingest' => (bool) $eligible];
    }

    public function assertReceipt(CollectionReceipt $receipt): void
    {
        $p = $receipt->account->policy ?? [];
        if (($p['automation_enabled'] ?? false) !== true || empty($p['enabled_from'])
            || ! $receipt->automation_eligible_at_ingest || ! Str::isUuid($receipt->automation_epoch ?? '')
            || ! hash_equals((string) ($p['automation_epoch'] ?? ''), $receipt->automation_epoch)) {
            throw new CollectionException('receipt_outside_automation_epoch');
        }
        $cutoff = Carbon::parse($p['enabled_from'])->utc();
        foreach ([$receipt->provider_at, $receipt->first_received_at, $receipt->first_ingested_at] as $timestamp) {
            if (! $timestamp || $timestamp->lt($cutoff)) {
                throw new CollectionException('receipt_before_automatic_cutover');
            }
        }
        if (! $this->validAge($p['max_auto_age_seconds'] ?? null)) {
            throw new CollectionException('automatic_payment_age_policy_required');
        }
        if ($receipt->provider_at->gt(now()) || $receipt->provider_at->lt(now()->subSeconds($p['max_auto_age_seconds']))) {
            throw new CollectionException('receipt_outside_automatic_age');
        }
    }

    public function validAge(mixed $seconds): bool
    {
        return is_int($seconds) && $seconds >= 60 && $seconds <= 86400;
    }
}
