<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\PaymentFulfillmentOperation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionApprovalService
{
    public function reserve(int $receiptId, string $intentId, int $expectedVersion, ?User $actor, bool $automatic = false): CollectionAllocation
    {
        $receipt = CollectionReceipt::findOrFail($receiptId);
        $intent = CollectionPurchaseIntent::findOrFail($intentId);
        if (! $automatic) {
            if (! $actor) {
                throw new CollectionException('staff_review_required', 403);
            }
            app(CollectionPurchaseService::class)->assertScope($receipt->account, Client::findOrFail($intent->client_id), $actor);
        }
        if ($existing = CollectionAllocation::where('receipt_id', $receipt->id)->first()) {
            if ($existing->intent_id !== $intentId) {
                throw new CollectionException('receipt_already_allocated');
            }

            return $existing;
        }
        $ids = array_map('intval', array_filter([$intent->client_id, data_get($intent->purchase_snapshot, 'subsidiary.client_id')]));
        sort($ids, SORT_NUMERIC);
        $reservation = new CollectionAllocation(['id' => (string) Str::uuid()]);

        return app(CollectionWriterGuard::class)->owned($reservation, fn () => app(CollectionFulfillmentService::class)->withLocks($ids,
            fn () => $this->reserveLocked($receipt, $intentId, $expectedVersion, $actor, $automatic, $reservation->id, $ids)));
    }

    private function reserveLocked(CollectionReceipt $receipt, string $intentId, int $expectedVersion, ?User $actor, bool $automatic, string $id, array $lockedIds): CollectionAllocation
    {
        return DB::transaction(function () use ($receipt, $intentId, $expectedVersion, $actor, $automatic, $id, $lockedIds) {
            $account = CollectionAccount::whereKey($receipt->account_id)->lockForUpdate()->firstOrFail();
            $r = CollectionReceipt::lockForUpdate()->findOrFail($receipt->id);
            $intent = CollectionPurchaseIntent::whereKey($intentId)->lockForUpdate()->firstOrFail();
            if (! $automatic) {
                if (! $actor) {
                    throw new CollectionException('staff_review_required', 403);
                }
                app(CollectionPurchaseService::class)->assertScope($account, Client::findOrFail($intent->client_id), $actor);
            }
            $existing = CollectionAllocation::where('receipt_id', $r->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->intent_id !== $intentId) {
                    throw new CollectionException('receipt_already_allocated');
                }

                return $existing;
            }
            if ($r->version !== $expectedVersion) {
                throw new CollectionException('receipt_version_changed');
            }
            app(CollectionGate::class)->assertFunds($r);
            $client = app(CollectionPurchaseService::class)->validate($intent, $r, $automatic);
            $p = $intent->purchase_snapshot;
            $platforms = [(int) $client->platform_id];
            if (! empty($p['subsidiary'])) {
                $platforms[] = (int) $p['subsidiary']['platform_id'];
                if ($actor) {
                    app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, (int) $p['subsidiary']['platform_id']);
                }
                if (! data_get($account->policy, 'subsidiary_grant_approved_by') || ! data_get($account->policy, 'subsidiary_grant_valid_until')
                    || now()->gte(\Illuminate\Support\Carbon::parse($account->policy['subsidiary_grant_valid_until']))) {
                    throw new CollectionException('subsidiary_policy_grant_required');
                }
            } elseif (config('collection.subsidiary_platforms.'.$client->platform_id)) {
                throw new CollectionException('linked_subsidiary_profile_required');
            }
            app(CollectionGate::class)->assertWrites($account, $platforms, $automatic);
            $payment = app(CollectionDecisionService::class)->existingPayment($r, $intent);
            if ($payment && PaymentFulfillmentOperation::where('payment_id', $payment->id)->exists()) {
                throw new CollectionException('existing_fulfillment_owner_requires_review');
            }
            $operationId = (string) Str::uuid();
            $ids = array_filter([$client->id, data_get($p, 'subsidiary.client_id')]);
            $ids = array_map('intval', $ids);
            sort($ids, SORT_NUMERIC);
            if ($ids !== $lockedIds) {
                throw new CollectionException('purchase_profile_binding_changed');
            }
            foreach ($ids as $clientId) {
                $c = Client::lockForUpdate()->findOrFail($clientId);
                if (DB::table('collection_profile_holds')->where('client_id', $c->id)->exists()) {
                    throw new CollectionException('collection_operation_owns_profile');
                }
                // Preserve the wallet coordinator when installed; collection capture
                // also ships independently of that separate, gated feature.
                if (class_exists(\App\Services\WalletLifecycleGuard::class)) {
                    app(\App\Services\WalletLifecycleGuard::class)->assertDispatch($c, null, $payment);
                }
            }
            $allocation = CollectionAllocation::create(['id' => $id, 'receipt_id' => $r->id, 'intent_id' => $intentId, 'intent_version' => $intent->version,
                'purchase_snapshot' => $p, 'payment_id' => $payment?->id, 'operation_id' => $operationId, 'decision_source' => $automatic ? 'automatic' : 'staff']);
            if ($automatic) {
                app(CollectionExposureService::class)->reserve($r, $allocation);
            }
            foreach ($ids as $clientId) {
                DB::table('collection_profile_holds')->insert(['client_id' => $clientId, 'allocation_id' => $id, 'operation_id' => $operationId, 'created_at' => now()]);
            }
            $intent->increment('used_count');
            $r->update(['decision_state' => 'dispatching', 'version' => $r->version + 1]);
            app(CollectionPurchaseService::class)->audit($r, $actor?->id, 'collection_allocation_reserved', ['allocation_id' => $id, 'operation_id' => $operationId, 'purchase' => $p, 'decision_source' => $allocation->decision_source], 'reserve:'.$id);
            app(CollectionIngestService::class)->outbox('fulfill', $id, 'fulfill:'.$id);

            return $allocation;
        }, 3);
    }
}
