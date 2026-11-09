<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use Illuminate\Support\Facades\DB;

class CollectionStandingAgreementService
{
    public function create(CollectionReceipt $receipt, User $actor, array $input): array
    {
        app(MarketAuthorizationService::class)->ensureManager($actor);
        app(CollectionAccessService::class)->receipt($actor, $receipt->id);
        $client = Client::findOrFail($input['client_id']);
        app(CollectionPurchaseService::class)->assertScope($receipt->account, $client, $actor);
        $key = 'standing-agreement:'.$receipt->account_id.':'.$actor->id.':'.$input['request_key'];
        $digest = app(CollectionSalesService::class)->digest($input);

        return DB::transaction(function () use ($receipt, $actor, $input, $client, $key, $digest) {
            $account = CollectionAccount::lockForUpdate()->findOrFail($receipt->account_id);
            $r = CollectionReceipt::lockForUpdate()->findOrFail($receipt->id);
            $prior = DB::table('collection_audit')->where('dedupe_key', $key)->first();
            if ($prior) {
                $saved = json_decode($prior->context, true, 512, JSON_THROW_ON_ERROR);
                if (! hash_equals($saved['digest'], $digest)) {
                    throw new CollectionException('standing_agreement_request_key_conflict');
                }

                return $saved['result'];
            }
            if ($r->version !== (int) $input['receipt_version']) {
                throw new CollectionException('receipt_version_changed');
            }
            app(CollectionGate::class)->assertFunds($r);
            if (! $r->payer_fingerprint || ! app(CollectionPayerEvidenceService::class)->forReceipt($r)['complete']) {
                throw new CollectionException('complete_unconflicted_payer_required');
            }
            $existing = CollectionPurchaseIntent::where('account_id', $account->id)->where('client_id', $client->id)
                ->where('authority', 'standing_agreement')->whereNull('revoked_at')->where('valid_until', '>', now())
                ->whereColumn('used_count', '<', 'max_renewals')->whereIn('payer_link_id', DB::table('collection_payer_links')
                ->where('account_id', $account->id)->where('payer_fingerprint', $r->payer_fingerprint)->select('id'))->exists();
            if ($existing) {
                throw new CollectionException('current_standing_agreement_already_exists');
            }
            $service = app(CollectionPurchaseService::class);
            $link = $service->reviewPayer($r, $client, $actor, true, $input['valid_until']);
            $intent = $service->create($account, $client, $actor, $input + ['authority' => 'standing_agreement', 'payer_link_id' => $link->id]);
            $exclusions = DB::table('collection_match_exclusions')->where('account_id', $account->id)->where('payer_fingerprint', $r->payer_fingerprint)
                ->where('client_id', $client->id)->whereNull('cleared_at')->lockForUpdate()->get();
            $requested = collect($input['clear_exclusions'] ?? [])->keyBy('id');
            if ($exclusions->count() !== $requested->count()) {
                throw new CollectionException('current_match_exclusions_require_explicit_review');
            }
            foreach ($exclusions as $exclusion) {
                if ((int) data_get($requested->get($exclusion->id), 'version', 0) !== (int) $exclusion->version) {
                    throw new CollectionException('match_exclusion_review_changed');
                }
                DB::table('collection_match_exclusions')->where('id', $exclusion->id)->where('version', $exclusion->version)->update([
                    'cleared_at' => now(), 'cleared_by' => $actor->id, 'clear_reason' => $input['reason'], 'clear_intent_id' => $intent->id,
                    'version' => $exclusion->version + 1]);
            }
            $result = ['intent_id' => $intent->id, 'client_id' => $client->id, 'purchase' => $intent->purchase_snapshot,
                'valid_from' => $intent->valid_from, 'valid_until' => $intent->valid_until, 'max_renewals' => $intent->max_renewals,
                'future_payments_only' => true, 'cleared_exclusions' => $exclusions->pluck('id')->all()];
            $service->audit($r, $actor->id, 'standing_agreement_reviewed', ['result' => $result, 'digest' => $digest, 'reason' => $input['reason'],
                'client_id' => $client->id, 'purchase' => $intent->purchase_snapshot], $key);

            return $result;
        }, 3);
    }
}
