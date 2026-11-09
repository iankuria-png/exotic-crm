<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionMatchExclusionService
{
    /** Caller must own the account/receipt transaction before reserving a correction. */
    public function record(CollectionReceipt $receipt, int $sourceClient, ?int $targetClient, string $owner, int $actor, string $reason): void
    {
        if (! $receipt->payer_fingerprint || $sourceClient === $targetClient) {
            return;
        }
        $links = CollectionPayerLink::where('account_id', $receipt->account_id)->where('payer_fingerprint', $receipt->payer_fingerprint)
            ->where('client_id', $sourceClient)->lockForUpdate()->get();
        $intents = CollectionPurchaseIntent::where('account_id', $receipt->account_id)->where('client_id', $sourceClient)
            ->where(fn ($q) => $q->where('provider_reference', $receipt->reference_norm)->orWhereIn('payer_link_id', $links->pluck('id')))
            ->lockForUpdate()->get();
        foreach ($links as $link) {
            if (! $link->revoked_at) {
                $link->update(['revoked_at' => now(), 'auto_allowed' => false]);
            }
        }
        foreach ($intents as $intent) {
            if (! $intent->revoked_at) {
                $intent->update(['revoked_at' => now(), 'version' => $intent->version + 1]);
            }
        }
        DB::table('collection_match_exclusions')->insertOrIgnore(['id' => (string) Str::uuid(), 'account_id' => $receipt->account_id,
            'payer_fingerprint' => $receipt->payer_fingerprint, 'client_id' => $sourceClient, 'source_owner' => $owner,
            'receipt_id' => $receipt->id, 'actor_id' => $actor, 'reason' => $reason, 'effective_at' => now()]);
    }

    public function clientIds(CollectionReceipt $receipt): array
    {
        if (! $receipt->payer_fingerprint) {
            return [];
        }

        return DB::table('collection_match_exclusions')->where('account_id', $receipt->account_id)
            ->where('payer_fingerprint', $receipt->payer_fingerprint)->whereNull('cleared_at')->pluck('client_id')->map(fn ($id) => (int) $id)->all();
    }
}
