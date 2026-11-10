<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\ClientMonetizationPass;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionBenefitEffect;
use App\Models\Collections\CollectionCorrection;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Commission;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletRebate;
use App\Services\DealPaymentService;
use App\Services\MarketAuthorizationService;
use App\Services\SubscriptionProvisioningService;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionCorrectionService
{
    public function preview(CollectionAllocation $allocation, User $actor, string $action, ?string $targetIntentId, int $version, string $reason): CollectionCorrection
    {
        $this->authorize($allocation, $actor);
        if ($allocation->version !== $version || ! in_array($action, ['undo_effect', 'reassign', 'change_package']) || trim($reason) === '') {
            throw new CollectionException('correction_version_or_action_invalid', 422);
        }
        $target = $targetIntentId ? CollectionPurchaseIntent::findOrFail($targetIntentId) : null;
        if ($target) {
            app(CollectionPurchaseService::class)->assertScope($allocation->receipt->account, Client::findOrFail($target->client_id), $actor);
            $this->authorizeSubsidiary($target->purchase_snapshot, $actor);
        }
        $preview = ['cash_changed' => false, 'can_execute' => false, 'reasons' => [], 'source_steps' => [], 'target_purchase' => $target?->purchase_snapshot,
            'source_client' => Client::whereKey($allocation->purchase_snapshot['client_id'])->first(['id', 'name', 'platform_id']),
            'target_client' => $target ? Client::whereKey($target->client_id)->first(['id', 'name', 'platform_id']) : null,
            'target_intent_version' => $target?->version, 'action' => $action, 'source_purchase' => $allocation->purchase_snapshot];
        try {
            $preActivation = ! $allocation->payment_id && ! \App\Models\PaymentFulfillmentOperation::whereKey($allocation->operation_id)->exists()
                && in_array($allocation->state, ['reserved', 'unassigned']);
            $preview['pre_activation'] = $preActivation;
            if (! $preActivation && ! in_array($allocation->state, ['active_review_pending', 'reviewed', 'unassigned'])) {
                throw new CollectionException('finish_or_recover_existing_operation_first');
            }
            $r = $allocation->receipt;
            app(CollectionGate::class)->assertFunds($r);
            if ($target) {
                app(CollectionPurchaseService::class)->validate($target, $r, false);
                if ($target->platform_id !== (int) $allocation->purchase_snapshot['platform_id']) {
                    throw new CollectionException('cross_market_correction_requires_recovery');
                }
            } elseif ($action !== 'undo_effect') {
                throw new CollectionException('target_purchase_required', 422);
            }
            if (! $preActivation && $action !== 'undo_effect' && config('collection.correction_duration_policy') !== 'full_purchased') {
                throw new CollectionException('correction_duration_policy_required');
            }
            $this->assertBenefitsReversible($allocation);
            $effects = $this->currentEffects($allocation);
            if (! $preActivation && $allocation->state !== 'unassigned' && (! $effects->contains(fn ($e) => in_array($e->effect_kind, ['primary_paid', 'target_paid'])))) {
                throw new CollectionException('historical_benefit_provenance_requires_recovery');
            }
            foreach ($effects as $effect) {
                $preview['source_steps'][] = $this->sourceStep($effect);
            }
            $platformIds = $effects->pluck('platform_id')->all();
            if ($target && ! $preActivation) {
                $platformIds = array_merge($platformIds, array_filter([$target->platform_id, data_get($target->purchase_snapshot, 'subsidiary.platform_id')]));
                $targetIdentity = $target->purchase_snapshot;
                $read = WpSyncService::forPlatform($target->platform_id)->subscriptionOperation($targetIdentity['wp_post_id']);
                if (data_get($read, 'capabilities.compensate') !== 2) {
                    throw new CollectionException('wordpress_compensation_v2_required');
                }
                app(CollectionEntitlementPolicy::class)->target($read['snapshot'] ?? [], $targetIdentity);
                $preview['target_fingerprint'] = $read['snapshot']['fingerprint'];
                $preview['target_profiles'] = [['client_id' => $targetIdentity['client_id'], 'platform_id' => $targetIdentity['platform_id'],
                    'wp_post_id' => $targetIdentity['wp_post_id'], 'expected_fingerprint' => $read['snapshot']['fingerprint']]];
                if ($secondary = ($targetIdentity['subsidiary'] ?? null)) {
                    $secondaryRead = WpSyncService::forPlatform($secondary['platform_id'])->subscriptionOperation($secondary['wp_post_id']);
                    if (data_get($secondaryRead, 'capabilities.compensate') !== 2 || data_get($secondaryRead, 'capabilities.durable_correction_owner') !== 1) {
                        throw new CollectionException('wordpress_correction_owner_required');
                    }
                    $preview['target_profiles'][] = ['client_id' => $secondary['client_id'], 'platform_id' => $secondary['platform_id'],
                        'wp_post_id' => $secondary['wp_post_id'], 'expected_fingerprint' => $secondaryRead['snapshot']['fingerprint']];
                }
                // Same-profile package changes are recomputed after source compensation.
            }
            app(CollectionGate::class)->assertWrites($r->account, array_unique($platformIds ?: [$allocation->purchase_snapshot['platform_id']]), false, ! $preActivation);
            $preview['can_execute'] = true;
        } catch (CollectionException $e) {
            $preview['reasons'][] = $e->reason;
        }

        return CollectionCorrection::create(['id' => (string) Str::uuid(), 'allocation_id' => $allocation->id, 'allocation_version' => $version,
            'action' => $action, 'target_intent_id' => $target?->id, 'actor_id' => $actor->id, 'reason' => trim($reason), 'preview' => $preview, 'expires_at' => now()->addMinutes(10)]);
    }

    public function execute(CollectionCorrection $correction, User $actor, int $version, string $requestKey): CollectionCorrection
    {
        $allocation = CollectionAllocation::findOrFail($correction->allocation_id);
        $this->authorize($allocation, $actor);
        if ($correction->target_intent_id) {
            $target = CollectionPurchaseIntent::findOrFail($correction->target_intent_id);
            app(CollectionPurchaseService::class)->assertScope($allocation->receipt->account, Client::findOrFail($target->client_id), $actor);
            $this->authorizeSubsidiary($target->purchase_snapshot, $actor);
        }
        $correction = DB::transaction(function () use ($allocation, $correction, $version, $requestKey, $actor) {
            CollectionAccount::whereKey($allocation->receipt->account_id)->lockForUpdate()->firstOrFail();
            $a = CollectionAllocation::lockForUpdate()->findOrFail($allocation->id);
            $c = CollectionCorrection::lockForUpdate()->findOrFail($correction->id);
            if ($c->request_key) {
                if ($c->request_key !== $requestKey) {
                    throw new CollectionException('correction_request_key_conflict');
                }

                return $c;
            }
            if ($c->version !== $version || $c->allocation_version !== $a->version || $c->expires_at->isPast() || ! $c->preview['can_execute']) {
                throw new CollectionException('correction_preview_stale_or_held');
            }
            app(CollectionSubsidiaryFulfillmentService::class)->fence($a);
            app(CollectionMatchExclusionService::class)->record($a->receipt, (int) $a->purchase_snapshot['client_id'],
                isset($c->preview['target_purchase']['client_id']) ? (int) $c->preview['target_purchase']['client_id'] : null,
                'allocation-correction:'.$c->id, $actor->id, $c->reason);
            if (data_get($c->preview, 'pre_activation')) {
                return $this->reassignBeforeDispatch($a, $c, $actor, $requestKey);
            }
            app(CollectionGate::class)->assertFunds($a->receipt);
            $this->assertBenefitsReversible($a);
            if ($c->target_intent_id) {
                $target = CollectionPurchaseIntent::lockForUpdate()->findOrFail($c->target_intent_id);
                app(CollectionPurchaseService::class)->validate($target, $a->receipt, false);
                if ($target->version !== $c->preview['target_intent_version']) {
                    throw new CollectionException('target_authority_changed');
                }
                $target->increment('used_count');
            }
            $ids = array_unique(array_filter(array_merge(array_column($c->preview['source_steps'], 'client_id'),
                [data_get($c->preview, 'target_purchase.client_id'), data_get($c->preview, 'target_purchase.subsidiary.client_id')])));
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                Client::lockForUpdate()->findOrFail($id);
                $hold = DB::table('collection_profile_holds')->where('client_id', $id)->first();
                if ($hold && $hold->allocation_id !== $a->id) {
                    throw new CollectionException('correction_profile_already_owned');
                }
                DB::table('collection_profile_holds')->updateOrInsert(['client_id' => $id], ['allocation_id' => $a->id, 'operation_id' => $a->operation_id, 'created_at' => now()]);
            }
            foreach ($c->preview['source_steps'] as $step) {
                CollectionBenefitEffect::create(['id' => (string) Str::uuid(), 'parent_operation_id' => $a->operation_id, 'allocation_id' => $a->id,
                    'correction_id' => $c->id, 'effect_kind' => 'source_compensation', 'platform_id' => $step['platform_id'], 'client_id' => $step['client_id'],
                    'wp_post_id' => $step['wp_post_id'], 'wp_user_id' => $step['wp_user_id'], 'deal_id' => $step['deal_id'],
                    'before_state' => $step['before_state'], 'contribution' => ['source_effect_id' => $step['source_effect_id']], 'target_state' => $step['target_state']]);
            }
            $a->update(['state' => 'correcting', 'version' => $a->version + 1]);
            app(CollectionExposureService::class)->reopenReview($a->receipt);
            $c->update(['state' => 'reserved', 'request_key' => $requestKey]);
            app(CollectionPurchaseService::class)->audit($a->receipt, $actor->id, 'correction_reserved', ['correction_id' => $c->id, 'reason' => $c->reason, 'cash_changed' => false], 'correction:'.$c->id);

            return $c;
        }, 3);

        return $this->resume($correction, $actor);
    }

    public function resume(CollectionCorrection $correction, User $actor): CollectionCorrection
    {
        $a = CollectionAllocation::findOrFail($correction->allocation_id);
        $this->authorize($a, $actor);
        if (in_array($correction->state, ['verified', 'verified_release_pending'], true)) {
            $this->releaseOwners($correction);
            $correction->update(['state' => 'verified']);

            return $correction;
        }
        if (! $correction->request_key) {
            throw new CollectionException('correction_not_reserved');
        }

        return app(CollectionWriterGuard::class)->owned($a, function () use ($a, $correction, $actor) {
            $ids = DB::table('collection_profile_holds')->where('allocation_id', $a->id)->orderBy('client_id')->pluck('client_id')->map(fn ($id) => (int) $id)->all();

            return app(CollectionFulfillmentService::class)->withLocks($ids, function () use ($a, $correction, $actor) {
                try {
                    $profiles = array_map(fn ($step) => ['client_id' => $step['client_id'], 'platform_id' => $step['platform_id'], 'wp_post_id' => $step['wp_post_id'],
                        'source_operation_id' => $step['source_effect_id'], 'expected_fingerprint' => $step['before_state']['fingerprint']], $correction->preview['source_steps']);
                    foreach (($correction->preview['target_profiles'] ?? []) as $targetProfile) {
                        if (! collect($profiles)->contains('client_id', $targetProfile['client_id'])) {
                            $profiles[] = $targetProfile;
                        }
                    }
                    app(CollectionCorrectionOwnership::class)->acquireAll($correction->id, $profiles);
                    $correction->update(['state' => 'source_correcting']);
                    foreach (CollectionBenefitEffect::where('correction_id', $correction->id)->where('effect_kind', 'source_compensation')->orderBy('client_id')->get() as $effect) {
                        app(CollectionFulfillmentService::class)->dispatchEffect($a, $effect);
                    }
                    if ($correction->target_intent_id) {
                        $correction->update(['state' => 'target_applying']);
                        $this->applyTarget($a, $correction, $actor);
                    }
                    $this->finalize($a, $correction, $actor);
                    $this->releaseOwners($correction);
                } catch (\Throwable $e) {
                    if ($correction->fresh()->state === 'verified') {
                        $correction->update(['state' => 'verified_release_pending']);
                        app(CollectionPurchaseService::class)->audit($a->receipt, $actor->id, 'correction_owner_release_pending', ['correction_id' => $correction->id]);

                        return $correction->fresh();
                    }
                    $correction->update(['state' => 'uncertain']);
                    $a->update(['state' => 'correcting']);
                    $a->receipt->update(['decision_state' => 'uncertain']);
                    app(CollectionPurchaseService::class)->audit($a->receipt, $actor->id, 'correction_held', ['correction_id' => $correction->id, 'reason' => $e instanceof CollectionException ? $e->reason : 'site_confirmation_required']);
                }

                return $correction->fresh();
            });
        });
    }

    private function releaseOwners(CollectionCorrection $correction): void
    {
        $operations = CollectionBenefitEffect::where('correction_id', $correction->id)->where('state', 'succeeded')->get(['id', 'platform_id', 'wp_post_id'])->toArray();
        app(CollectionCorrectionOwnership::class)->releaseAll($correction->id, $operations);
    }

    private function reassignBeforeDispatch(CollectionAllocation $a, CollectionCorrection $c, User $actor, string $key): CollectionCorrection
    {
        if ($a->payment_id || \App\Models\PaymentFulfillmentOperation::whereKey($a->operation_id)->lockForUpdate()->first()
            || $a->effects()->exists() || ! in_array($a->state, ['reserved', 'unassigned'])) {
            throw new CollectionException('activation_started_refresh_correction');
        }
        app(CollectionGate::class)->assertFunds($a->receipt);
        $oldIntent = CollectionPurchaseIntent::lockForUpdate()->findOrFail($a->intent_id);
        $target = $c->target_intent_id ? CollectionPurchaseIntent::lockForUpdate()->findOrFail($c->target_intent_id) : null;
        if ($target) {
            if ($target->id === $oldIntent->id) {
                throw new CollectionException('choose_a_changed_purchase');
            }
            app(CollectionPurchaseService::class)->validate($target, $a->receipt, false);
            if ($target->version !== $c->preview['target_intent_version'] || $target->payment_id) {
                throw new CollectionException('target_purchase_claim_changed');
            }
            $p = $target->purchase_snapshot;
            $ids = array_filter([$p['client_id'], data_get($p, 'subsidiary.client_id')]);
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $id) {
                Client::lockForUpdate()->findOrFail($id);
                $hold = DB::table('collection_profile_holds')->where('client_id', $id)->lockForUpdate()->first();
                if ($hold && $hold->allocation_id !== $a->id) {
                    throw new CollectionException('correction_profile_already_owned');
                }
            }
            app(CollectionGate::class)->assertWrites($a->receipt->account, array_filter([$p['platform_id'], data_get($p, 'subsidiary.platform_id')]), false);
            DB::table('collection_profile_holds')->where('allocation_id', $a->id)->delete();
            foreach ($ids as $id) {
                DB::table('collection_profile_holds')->insert(['client_id' => $id, 'allocation_id' => $a->id, 'operation_id' => $a->operation_id, 'created_at' => now()]);
            }
            $target->increment('used_count');
            $a->update(['intent_id' => $target->id, 'intent_version' => $target->version, 'purchase_snapshot' => $p, 'decision_source' => 'staff', 'state' => 'reserved', 'version' => $a->version + 1]);
            app(CollectionIngestService::class)->outbox('fulfill', $a->id, 'fulfill:'.$a->id.':'.$a->version);
        } else {
            $a->update(['state' => 'unassigned', 'version' => $a->version + 1]);
            DB::table('collection_profile_holds')->where('allocation_id', $a->id)->delete();
        }
        $oldIntent->update(['revoked_at' => now()]);
        CollectionPayerLink::where('account_id', $a->receipt->account_id)->where('payer_fingerprint', $a->receipt->payer_fingerprint)->update(['auto_allowed' => false]);
        $a->receipt->update(['decision_state' => $target ? 'dispatching' : 'unassigned', 'version' => $a->receipt->version + 1]);
        $c->update(['state' => 'verified', 'request_key' => $key]);
        app(CollectionPurchaseService::class)->audit($a->receipt, $actor->id, 'undispatched_allocation_corrected', ['correction_id' => $c->id, 'cash_changed' => false, 'target_intent_id' => $target?->id], 'correction-verified:'.$c->id);

        return $c;
    }

    private function sourceStep(CollectionBenefitEffect $effect): array
    {
        $read = WpSyncService::forPlatform($effect->platform_id)->subscriptionOperation($effect->wp_post_id, $effect->id);
        if (data_get($read, 'capabilities.compensate') !== 2) {
            throw new CollectionException('wordpress_compensation_v2_required');
        }
        if (data_get($read, 'operation.contract_version') !== 2 || ! is_array(data_get($read, 'operation.before_state.meta_present'))
            || app(CollectionSalesService::class)->digest(data_get($read, 'operation.before_state', [])) !== app(CollectionSalesService::class)->digest($effect->before_state)) {
            throw new CollectionException('original_compensation_preimage_required');
        }
        $current = $read['snapshot'] ?? [];
        $policy = app(CollectionEntitlementPolicy::class);
        $policy->assertSnapshot($current, ['wp_post_id' => $effect->wp_post_id, 'wp_user_id' => $effect->wp_user_id]);
        // A chained, same-tier later purchase may survive. Untracked writes are never overwritten.
        $later = CollectionBenefitEffect::where('client_id', $effect->client_id)->where('state', 'succeeded')->whereNull('superseded_at')
            ->whereIn('effect_kind', ['primary_paid', 'subsidiary_free', 'target_paid', 'target_subsidiary'])
            ->where('id', '!=', $effect->id)->get()->filter(fn ($e) => ($e->contribution['start'] ?? 0) >= $effect->contribution['end'])
            ->sortBy(fn ($e) => $e->contribution['start'])->values();
        $expected = $effect->verified_state;
        $target = $effect->before_state;
        $cursor = $policy->expiry($target);
        if ($later->isNotEmpty()) {
            // v1 snapshots do not contain a site timezone or a representable
            // schedule for rebasing later contributions. Keep all legitimate
            // service intact until a reviewed recovery can pin that full state.
            throw new CollectionException('later_paid_coverage_requires_recovery');
        }
        if (($current['fingerprint'] ?? '') !== ($expected['fingerprint'] ?? '')) {
            throw new CollectionException('untracked_entitlement_change_requires_recovery');
        }
        $expiryKey = ($target['post_type'] ?? '') === 'agency' ? 'agency_expire' : 'escort_expire';
        $target['meta'][$expiryKey] = (string) $cursor;
        foreach (['premium_expire' => 'premium', 'featured_expire' => 'featured', 'vvip_expire' => 'vvip'] as $expiry => $flag) {
            $target['meta'][$expiry] = ($target['meta'][$flag] ?? '') === '1' ? (string) $cursor : '';
        }
        if ($cursor <= now()->timestamp) {
            $target['post_status'] = $effect->before_state['post_status'] === 'private' ? 'private' : 'publish';
        }

        return ['source_effect_id' => $effect->id, 'client_id' => $effect->client_id, 'platform_id' => $effect->platform_id,
            'wp_post_id' => $effect->wp_post_id, 'wp_user_id' => $effect->wp_user_id, 'deal_id' => $effect->deal_id,
            'before_state' => $current, 'target_state' => ['contract_version' => 2, 'operation_kind' => 'compensate', 'source_operation_id' => $effect->id,
                'expected_fingerprint' => $current['fingerprint'], 'target_expiry' => $cursor, 'product_type' => $policy->tier($target),
                'crm_deal_id' => (int) ($target['meta']['crm_deal_id'] ?? 0), 'post_status' => $target['post_status'], 'restore_snapshot' => $target]];
    }

    private function applyTarget(CollectionAllocation $a, CollectionCorrection $c, User $actor): void
    {
        $p = $c->preview['target_purchase'];
        $this->authorizeSubsidiary($p, $actor);
        $primary = CollectionBenefitEffect::where('correction_id', $c->id)->where('effect_kind', 'target_paid')->first();
        if (! $primary) {
            $identity = Client::findOrFail($p['client_id']);
            if ($identity->wp_post_id !== (int) $p['wp_post_id'] || $identity->wp_user_id !== (int) $p['wp_user_id'] || $identity->profile_paused_at || $identity->profile_removal_requested_at) {
                throw new CollectionException('correction_target_profile_changed');
            }
            $read = WpSyncService::forPlatform($p['platform_id'])->subscriptionOperation($p['wp_post_id']);
            $sourceSameClient = collect($c->preview['source_steps'])->contains('client_id', $p['client_id']);
            if (! $sourceSameClient && ($read['snapshot']['fingerprint'] ?? '') !== ($c->preview['target_fingerprint'] ?? '')) {
                throw new CollectionException('correction_target_state_changed');
            }
            $target = app(CollectionEntitlementPolicy::class)->target($read['snapshot'] ?? [], $p);
            $primary = DB::transaction(function () use ($a, $c, $p, $actor, $read, $target, $identity) {
                $deal = app(DealPaymentService::class)->createPendingDealFromCatalog($identity, $p['product_id'], $p['product_price_id'], null, $actor->id, null);
                $deal->update(['amount' => app(CollectionFulfillmentService::class)->decimal($p['amount_minor']), 'payment_id' => $a->payment_id, 'origin' => 'manual_collection_correction',
                    'original_amount' => $p['original_amount'], 'discount_percentage' => $p['discount_percentage'], 'discount_approved_by' => $p['discount_approved_by'], 'discount_source' => $p['discount_source']]);

                return CollectionBenefitEffect::create(['id' => (string) Str::uuid(), 'parent_operation_id' => $a->operation_id, 'allocation_id' => $a->id, 'correction_id' => $c->id,
                    'effect_kind' => 'target_paid', 'platform_id' => $p['platform_id'], 'client_id' => $p['client_id'], 'wp_post_id' => $p['wp_post_id'], 'wp_user_id' => $p['wp_user_id'],
                    'deal_id' => $deal->id, 'before_state' => $read['snapshot'], 'contribution' => $target['contribution'],
                    'target_state' => array_diff_key($target, ['contribution' => true]) + ['crm_deal_id' => $deal->id]]);
            });
        }
        app(CollectionFulfillmentService::class)->dispatchEffect($a, $primary);
        if (! empty($p['subsidiary'])) {
            // The same absolute-period, tier-preserving domain is used for corrected exposure.
            app(CollectionFulfillmentService::class)->applyCorrectedSubsidiary($a, $c, $primary);
        }
    }

    private function finalize(CollectionAllocation $allocation, CollectionCorrection $correction, User $actor): void
    {
        DB::transaction(function () use ($allocation, $correction, $actor) {
            CollectionAccount::whereKey($allocation->receipt->account_id)->lockForUpdate()->firstOrFail();
            $a = CollectionAllocation::lockForUpdate()->findOrFail($allocation->id);
            $c = CollectionCorrection::lockForUpdate()->findOrFail($correction->id);
            if ($c->state === 'verified') {
                return;
            }
            $effects = CollectionBenefitEffect::where('correction_id', $c->id)->get();
            if ($effects->contains(fn ($e) => $e->state !== 'succeeded')) {
                throw new CollectionException('correction_steps_unconfirmed');
            }
            $this->assertBenefitsReversible($a);
            foreach ($c->preview['source_steps'] as $step) {
                CollectionBenefitEffect::whereKey($step['source_effect_id'])->update(['superseded_at' => now()]);
                $deal = Deal::findOrFail($step['deal_id']);
                $deal->update(['status' => 'cancelled', 'cancellation_notes' => 'Payment matching correction: '.$c->reason]);
                foreach (Commission::where('deal_id', $deal->id)->get() as $commission) {
                    $commission->update(['status' => 'void', 'meta' => array_merge($commission->meta ?? [], ['collection_correction_id' => $c->id, 'reversed_amount' => (string) $commission->amount])]);
                    app(CollectionPurchaseService::class)->audit($a->receipt, $actor->id, 'commission_corrected', ['commission_id' => $commission->id, 'reversing_amount' => '-'.$commission->amount, 'correction_id' => $c->id], 'commission-correction:'.$commission->id);
                }
                ClientMonetizationPass::where('source_deal_id', $deal->id)->whereNull('claimed_at')->update(['status' => 'revoked', 'active_marker' => null]);
            }
            $payment = Payment::findOrFail($a->payment_id);
            $target = $effects->firstWhere('effect_kind', 'target_paid');
            if ($target) {
                $p = $c->preview['target_purchase'];
                $payment->update(['client_id' => $p['client_id'], 'user_id' => $p['wp_user_id'], 'escort_post_id' => $p['wp_post_id'], 'deal_id' => $target->deal_id, 'product_id' => $p['product_id']]);
                foreach ($effects->whereIn('effect_kind', ['target_paid', 'target_subsidiary']) as $effect) {
                    $effect->state = 'wp_verified';
                    app(SubscriptionProvisioningService::class)->recordVerifiedCollectionEffect($effect, $a);
                }
                $a->update(['intent_id' => $c->target_intent_id, 'intent_version' => $c->preview['target_intent_version'], 'purchase_snapshot' => $p, 'state' => 'active_review_pending']);
            } else {
                $payment->update(['client_id' => null, 'deal_id' => null, 'user_id' => null, 'escort_post_id' => null]);
                $a->update(['state' => 'unassigned']);
            }
            // Corrections are negative training evidence. Automatic reuse must be reviewed again.
            CollectionPurchaseIntent::whereKey($allocation->intent_id)->update(['revoked_at' => now()]);
            $a->receipt->update(['decision_state' => $a->state, 'reviewed_at' => null, 'reviewed_by' => null, 'version' => $a->receipt->version + 1]);
            $a->increment('version');
            $c->update(['state' => 'verified']);
            if ($target && ($allocation->purchase_snapshot['subsidiary_mode'] ?? null) === 'independent') {
                $replacement = $a->purchase_snapshot;
                $replacement['subsidiary'] = null;
                $replacement['subsidiary_mode'] = 'independent';
                $replacement['subsidiary_platform_id'] = $allocation->purchase_snapshot['subsidiary_platform_id'];
                $a->update(['purchase_snapshot' => $replacement]);
                app(CollectionSubsidiaryFulfillmentService::class)->ensure($a, $target);
            }
            DB::table('collection_profile_holds')->where('allocation_id', $a->id)->delete();
            app(CollectionPurchaseService::class)->audit($a->receipt, $actor->id, 'correction_verified', ['correction_id' => $c->id, 'cash_changed' => false, 'payment_id' => $payment->id], 'correction-verified:'.$c->id);
        }, 3);
    }

    private function currentEffects(CollectionAllocation $a)
    {
        return CollectionBenefitEffect::where('allocation_id', $a->id)->where('state', 'succeeded')->whereNull('superseded_at')
            ->whereIn('effect_kind', ['primary_paid', 'subsidiary_free', 'target_paid', 'target_subsidiary'])->get();
    }

    private function assertBenefitsReversible(CollectionAllocation $a): void
    {
        if (! $a->payment_id) {
            return;
        }
        $dealIds = $this->currentEffects($a)->pluck('deal_id');
        if (Commission::whereIn('deal_id', $dealIds)->where(fn ($q) => $q->where('status', 'paid')->orWhereNotNull('payout_id'))->exists()
            || ClientMonetizationPass::whereIn('source_deal_id', $dealIds)->whereNotNull('claimed_at')->exists()
            || WalletRebate::where('source_payment_id', $a->payment_id)->where('status', '!=', 'reversed')->exists()) {
            throw new CollectionException('consumed_or_paid_benefit_requires_review');
        }
    }

    private function authorize(CollectionAllocation $a, User $actor): void
    {
        app(MarketAuthorizationService::class)->ensureRole($actor, ['admin', 'sub_admin', 'sales']);
        app(CollectionPurchaseService::class)->assertScope($a->receipt->account, Client::findOrFail($a->purchase_snapshot['client_id']), $actor);
        $this->authorizeSubsidiary($a->purchase_snapshot, $actor);
    }

    private function authorizeSubsidiary(array $p, User $actor): void
    {
        if (! empty($p['subsidiary'])) {
            app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, (int) $p['subsidiary']['platform_id']);
        }
    }
}
