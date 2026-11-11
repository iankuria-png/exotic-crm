<?php

namespace App\Services\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAllocation;
use App\Models\Collections\CollectionBenefitEffect;
use App\Models\Collections\CollectionSubsidiaryObligation;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\Platform;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Services\SubsidiaryProfileCopyService;
use App\Services\SubsidiaryTrialService;
use App\Services\SubsidiaryTrialWorkflow;
use App\Services\Trials\TrialApprovalService;
use App\Services\WpSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** One durable target owner, created after the paid primary succeeds. Never compensates the primary. */
class CollectionSubsidiaryFulfillmentService
{
    public function ensure(CollectionAllocation $a, CollectionBenefitEffect $primary): ?CollectionSubsidiaryObligation
    {
        if (($a->purchase_snapshot['subsidiary_mode'] ?? null) !== 'independent' || ! $a->purchase_snapshot['subsidiary_platform_id']) {
            return null;
        }

        return DB::transaction(function () use ($a, $primary) {
            CollectionAllocation::lockForUpdate()->findOrFail($a->id);
            $existing = CollectionSubsidiaryObligation::where('primary_effect_id', $primary->id)->first();
            if ($existing) {
                return $existing;
            }
            $generation = (int) CollectionSubsidiaryObligation::where('allocation_id', $a->id)->max('source_generation') + 1;
            $o = CollectionSubsidiaryObligation::create(['id' => (string) Str::uuid(), 'allocation_id' => $a->id, 'primary_effect_id' => $primary->id,
                'correction_id' => $primary->correction_id, 'source_generation' => $generation, 'source_deal_id' => $primary->deal_id,
                'target_platform_id' => $a->purchase_snapshot['subsidiary_platform_id'], 'interval' => $primary->contribution,
                'policy_snapshot' => $a->receipt->account->policy['independent_subsidiary'] ?? [], 'state' => 'pending']);
            Deal::whereKey($primary->deal_id)->update(['pending_subsidiary_trial' => ['status' => 'pending', 'platform_id' => $o->target_platform_id, 'collection_obligation_id' => $o->id]]);
            app(CollectionIngestService::class)->outbox('subsidiary', $o->id, 'subsidiary:'.$o->id);

            return $o;
        }, 3);
    }

    public function forRequest(FreeTrialRequest $r): ?CollectionSubsidiaryObligation
    {
        $id = $r->terms_json['collection_obligation_id'] ?? null;
        if (! $id) {
            return null;
        }
        $o = CollectionSubsidiaryObligation::findOrFail($id);
        if ((int) $o->trial_request_id !== (int) $r->id || (int) $o->source_deal_id !== (int) ($r->terms_json['source_deal_id'] ?? 0)
            || (int) $o->target_platform_id !== (int) $r->platform_id) {
            throw new CollectionException('subsidiary_owner_binding_changed');
        }

        return $o;
    }

    public function assertCurrent(CollectionSubsidiaryObligation $o): CollectionAllocation
    {
        $o->refresh();
        $a = $o->allocation->fresh();
        $latest = (int) CollectionSubsidiaryObligation::where('allocation_id', $a->id)->max('source_generation');
        if ($latest !== $o->source_generation || $o->state === 'canceled' || in_array($a->state, ['correcting', 'corrected', 'unassigned'])
            || DB::table('collection_corrections')->where('allocation_id', $a->id)->whereNotNull('request_key')->whereNotIn('state', ['verified', 'canceled'])->exists()) {
            throw new CollectionException('subsidiary_generation_superseded');
        }
        $primary = CollectionBenefitEffect::findOrFail($o->primary_effect_id);
        if ($primary->state !== 'succeeded' || $primary->superseded_at || $primary->deal_id != $o->source_deal_id) {
            throw new CollectionException('primary_subscription_not_confirmed');
        }
        app(CollectionGate::class)->assertFunds($a->receipt);
        $main = Deal::findOrFail($o->source_deal_id);
        if ($main->status !== 'active' || ! $main->expires_at || $main->expires_at->isPast() || $o->interval['end'] <= now()->timestamp) {
            throw new CollectionException('subsidiary_interval_elapsed');
        }

        return $a;
    }

    public function assertDelegation(CollectionSubsidiaryObligation $o, ?int $actorId = null): User
    {
        $a = $this->assertCurrent($o);
        $p = $a->receipt->account->policy['independent_subsidiary'] ?? [];
        if (! app(CollectionAutomationPolicy::class)->independentSubsidiary($a->receipt->account)
            || $p !== $o->policy_snapshot || empty($p['create_and_copy']) || ($actorId && (int) $p['approved_by'] !== $actorId)
            || (int) $o->target_platform_id !== 2 || (int) $a->purchase_snapshot['platform_id'] !== 6) {
            throw new CollectionException('subsidiary_policy_grant_required');
        }
        $actor = User::findOrFail($p['approved_by']);
        if ($actor->role !== 'admin' || ! $actor->isActive()) {
            throw new CollectionException('subsidiary_policy_grant_required');
        }
        foreach ([6, 2] as $platform) {
            app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $platform);
        }

        return $actor;
    }

    public function resume(string $id): CollectionSubsidiaryObligation
    {
        $o = CollectionSubsidiaryObligation::findOrFail($id);
        if (in_array($o->state, ['succeeded', 'already_active', 'canceled'])) {
            return $o;
        }
        try {
            $a = $this->assertCurrent($o);
            $savedRequest = $o->trial_request_id ? FreeTrialRequest::findOrFail($o->trial_request_id) : null;
            $actor = $savedRequest && $savedRequest->approved_via !== 'policy' ? User::findOrFail($savedRequest->requester_id) : $this->assertDelegation($o);
            $main = Deal::with('client.platform', 'product')->findOrFail($o->source_deal_id);
            $r = $o->trial_request_id ? FreeTrialRequest::findOrFail($o->trial_request_id) : $this->attach($o, $main, $actor);
            if (! $r) {
                return $o->fresh();
            }
            if (in_array($r->activation_result_json['profile_resolution'] ?? '', ['resolving', 'unknown'])) {
                $r = app(SubsidiaryTrialWorkflow::class)->recoverProfile($r);
            }
            if (! in_array($r->status, ['approved', 'activating'])) {
                $o->update(['state' => 'needs_approval', 'last_error_code' => 'subsidiary_approval_required']);

                return $o->fresh();
            }
            DB::transaction(fn () => app(TrialApprovalService::class)->assertDispatch(FreeTrialRequest::lockForUpdate()->findOrFail($r->id)));
            $r = app(SubsidiaryTrialWorkflow::class)->prepare($r);
            if (! $r->client_id || $r->status === 'uncertain') {
                $o->update(['state' => 'uncertain', 'last_error_code' => 'subsidiary_profile_resolution_pending']);

                return $o->fresh();
            }
            $copies = app(SubsidiaryProfileCopyService::class);
            if ($copies->tagged($r) && $copies->gate($r) === 'pending') {
                $o->update(['state' => 'copying', 'last_error_code' => null]);

                return $o->fresh();
            }
            $r->refresh();
            $this->assertCurrent($o);
            $client = app(CollectionPurchaseService::class)->selectableClients()->where('platform_id', $o->target_platform_id)->findOrFail($r->client_id);
            $identity = ['client_id' => $client->id, 'platform_id' => $client->platform_id, 'wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id];
            if ($o->target_identity && $o->target_identity !== $identity) {
                throw new CollectionException('subsidiary_profile_binding_changed');
            }
            $o->update(['target_identity' => $identity]);
            app(CollectionWriterGuard::class)->owned($a, fn () => app(CollectionFulfillmentService::class)->withLocks([$client->id], function () use ($o, $a, $r, $main, $identity) {
                $this->assertCurrent($o);
                DB::transaction(fn () => app(TrialApprovalService::class)->assertDispatch(FreeTrialRequest::lockForUpdate()->findOrFail($r->id)));
                $read = WpSyncService::forPlatform($identity['platform_id'])->subscriptionOperation($identity['wp_post_id']);
                $policy = app(CollectionEntitlementPolicy::class);
                $policy->assertSnapshot($read['snapshot'] ?? [], $identity);
                $expiry = $policy->expiry($read['snapshot']);
                if ($expiry >= $o->interval['end'] && $policy->tier($read['snapshot']) === $o->interval['tier'] && $read['snapshot']['post_status'] === 'publish') {
                    $o->update(['state' => 'already_active', 'completed_at' => now(), 'last_error_code' => null]);
                    $r->update(['status' => 'canceled', 'active_identity' => null, 'activation_result_json' => array_merge($r->activation_result_json ?? [], ['outcome' => 'existing_coverage', 'client_id' => $identity['client_id']])]);

                    return;
                }
                $effect = $o->dependent_effect_id ? CollectionBenefitEffect::findOrFail($o->dependent_effect_id) : $this->prepareEffect($o, $a, $main, $identity, $read['snapshot']);
                app(CollectionFulfillmentService::class)->dispatchEffect($a, $effect);
                if ($effect->fresh()->state === 'succeeded') {
                    DB::transaction(function () use ($o, $r, $main, $effect) {
                        $o->update(['state' => 'succeeded', 'completed_at' => now(), 'last_error_code' => null]);
                        $r->update(['status' => 'active', 'active_identity' => null, 'activation_result_json' => array_merge($r->activation_result_json ?? [], ['collection_effect_id' => $effect->id, 'deal_id' => $effect->deal_id])]);
                        $main->update(['linked_deal_id' => $effect->deal_id, 'pending_subsidiary_trial' => array_merge($main->pending_subsidiary_trial ?? [], ['status' => 'satisfied'])]);
                        DB::table('collection_profile_holds')->where('allocation_id', $o->allocation_id)->where('client_id', $effect->client_id)->delete();
                    });
                } else {
                    $o->update(['state' => 'uncertain', 'last_error_code' => 'subsidiary_confirmation_pending']);
                }
            }));
        } catch (\Throwable $e) {
            $o->refresh();
            $reason = $e instanceof CollectionException ? $e->reason : 'subsidiary_setup_requires_review';
            $o->update(['state' => $o->dependent_effect_id ? 'uncertain' : ($reason === 'subsidiary_policy_grant_required' ? 'needs_approval' : 'needs_profile'), 'last_error_code' => $reason]);
            app(CollectionPurchaseService::class)->audit($o->allocation->receipt, null, 'subsidiary_obligation_held', ['obligation_id' => $o->id, 'reason' => $reason, 'exception_class' => get_class($e)]);
        }

        return $o->fresh();
    }

    private function attach(CollectionSubsidiaryObligation $o, Deal $main, User $actor, bool $delegated = true, ?string $pin = null): ?FreeTrialRequest
    {
        $options = $o->setup_options ?? [];
        $target = Platform::findOrFail($o->target_platform_id);
        $preview = app(SubsidiaryTrialService::class)->previewForDeal($main, $target, $options['client_id'] ?? null,
            $options + ['duration_mode' => 'match_primary', 'copy' => true, 'collection_obligation_id' => $o->id]);
        if ($preview['config_errors']) {
            throw new CollectionException('subsidiary_market_configuration_required');
        }
        if (! in_array($preview['resolution']['kind'], ['existing', 'create_if_needed', 'already_active'])) {
            throw new CollectionException($preview['resolution']['kind'] === 'ambiguous'
                ? 'subsidiary_multiple_profiles_require_selection' : 'subsidiary_profile_unavailable');
        }
        if (! empty($preview['duration']['error'])) {
            throw new CollectionException('subsidiary_period_requires_review');
        }
        $copy = $preview['copy_preview'] ?? ['status' => 'off'];
        if (! in_array($copy['status'], ['off', 'ready'])) {
            throw new CollectionException(match ($copy['status']) {
                'needs_selection' => 'subsidiary_location_or_currency_required',
                'blocked' => 'subsidiary_profile_copy_blocked',
                default => 'subsidiary_profile_copy_unavailable',
            });
        }
        $request = Request::create('/collection-subsidiary', 'POST');
        $request->setUserResolver(fn () => $actor);

        return DB::transaction(function () use ($o, $main, $actor, $request, $options, $delegated, $pin) {
            CollectionAllocation::lockForUpdate()->findOrFail($o->allocation_id);
            $locked = CollectionSubsidiaryObligation::lockForUpdate()->findOrFail($o->id);
            if ($delegated) {
                $this->assertDelegation($locked, $actor->id);
            } else {
                $this->assertCurrent($locked);
                app(CollectionAccessService::class)->receipt($actor, $locked->allocation->receipt_id);
                app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $locked->target_platform_id);
            }
            if ($locked->trial_request_id) {
                return FreeTrialRequest::findOrFail($locked->trial_request_id);
            }
            $input = array_diff_key($options, ['pin' => true]) + ['deal_id' => $main->id, 'platform_id' => $o->target_platform_id, 'duration_mode' => 'match_primary',
                'duration_days' => $main->duration_days, 'create_confirmed' => true, 'request_key' => $o->id, 'collection_obligation_id' => $o->id,
                'reason' => 'Automatic paired market for confirmed TZ receipt; policy approved by '.$actor->id];
            $terms = app(\App\Http\Controllers\CRM\FreeTrialApprovalController::class)->terms($request, 'subsidiary', $input);
            $r = $delegated ? app(TrialApprovalService::class)->createForCollection($actor, $terms, $o)
                : app(TrialApprovalService::class)->create($actor, $terms, $pin, $o->id);
            $locked->update(['trial_request_id' => $r->id, 'state' => 'pending', 'version' => $locked->version + 1]);
            $main->update(['pending_subsidiary_trial' => ['status' => 'pending', 'platform_id' => $o->target_platform_id,
                'trial_request_id' => $r->id, 'duration_days' => $main->duration_days, 'collection_obligation_id' => $o->id]]);

            return $r;
        }, 3);
    }

    private function prepareEffect(CollectionSubsidiaryObligation $o, CollectionAllocation $a, Deal $main, array $identity, array $before): CollectionBenefitEffect
    {
        $target = app(CollectionEntitlementPolicy::class)->target($before, $identity + ['tier' => $o->interval['tier'], 'duration_days' => $a->purchase_snapshot['duration_days']], $o->interval);
        $product = app(SubsidiaryTrialService::class)->collectionProduct(Platform::findOrFail($o->target_platform_id), $main);
        if (! $product || $product->tier !== $o->interval['tier']) {
            throw new CollectionException('subsidiary_matching_tier_required');
        }

        return DB::transaction(function () use ($o, $a, $identity, $before, $target, $product) {
            CollectionAllocation::lockForUpdate()->findOrFail($a->id);
            $o = CollectionSubsidiaryObligation::lockForUpdate()->findOrFail($o->id);
            $this->assertCurrent($o);
            if ($o->dependent_effect_id) {
                return CollectionBenefitEffect::findOrFail($o->dependent_effect_id);
            }
            $hold = DB::table('collection_profile_holds')->where('client_id', $identity['client_id'])->first();
            if ($hold && $hold->allocation_id !== $a->id) {
                throw new CollectionException('subsidiary_profile_owned_by_other_payment');
            }
            $deal = Deal::create(['platform_id' => $identity['platform_id'], 'client_id' => $identity['client_id'], 'product_id' => $product->id,
                'plan_type' => $o->interval['tier'], 'amount' => 0, 'currency' => $product->currency, 'duration' => 'weekly',
                'duration_days' => $a->purchase_snapshot['duration_days'], 'status' => 'pending', 'is_free_trial' => true,
                'free_trial_approved_by' => 'collection_subsidiary_policy', 'origin' => 'manual_collection_subsidiary', 'linked_deal_id' => $o->source_deal_id]);
            // Native zero-cash benefit record; the positive cash remains solely on the primary.
            $payment = app(\App\Services\DealPaymentService::class)->createManualPaymentForDeal($deal, Client::findOrFail($identity['client_id']), 'COLLECTION-FREE-'.$o->id, 0,
                ['amount' => 0, 'confirmed_by' => null, 'transaction_uuid' => 'collection_free_'.$o->id, 'status' => 'pending', 'raw_payload' => ['source' => 'subsidiary_trial_from_deal', 'main_deal_id' => $o->source_deal_id, 'allocation_id' => $a->id]]);
            $deal->update(['payment_id' => $payment->id]);
            $effect = CollectionBenefitEffect::create(['id' => (string) Str::uuid(), 'parent_operation_id' => $a->operation_id, 'allocation_id' => $a->id,
                'correction_id' => $o->correction_id, 'effect_kind' => $o->correction_id ? 'target_subsidiary' : 'subsidiary_free',
                'platform_id' => $identity['platform_id'], 'client_id' => $identity['client_id'], 'wp_post_id' => $identity['wp_post_id'], 'wp_user_id' => $identity['wp_user_id'],
                'deal_id' => $deal->id, 'before_state' => $before, 'contribution' => $o->interval, 'target_state' => array_diff_key($target, ['contribution' => true]) + ['crm_deal_id' => $deal->id]]);
            $o->update(['dependent_effect_id' => $effect->id, 'state' => 'prepared']);
            DB::table('collection_profile_holds')->updateOrInsert(['client_id' => $identity['client_id']], ['allocation_id' => $a->id, 'operation_id' => $a->operation_id, 'created_at' => now()]);

            return $effect;
        }, 3);
    }

    public function finish(CollectionSubsidiaryObligation $owner, User $actor, array $input): CollectionSubsidiaryObligation
    {
        app(CollectionAccessService::class)->receipt($actor, $owner->allocation->receipt_id);
        app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($actor, $owner->target_platform_id);
        $main = Deal::findOrFail($owner->source_deal_id);
        DB::transaction(function () use ($owner, $input) {
            CollectionAllocation::lockForUpdate()->findOrFail($owner->allocation_id);
            $o = CollectionSubsidiaryObligation::lockForUpdate()->findOrFail($owner->id);
            $this->assertCurrent($o);
            if ($o->version !== (int) $input['expected_version']) {
                throw new CollectionException('subsidiary_obligation_changed', 409);
            }
            if (! $o->trial_request_id) {
                $o->update(['setup_options' => array_intersect_key($input, array_flip(['client_id', 'copy_region_id', 'copy_city_id', 'copy_currency_id'])), 'version' => $o->version + 1]);
            }
        });
        $owner->refresh();
        if (! $owner->trial_request_id) {
            $this->attach($owner, $main, $actor, false, $input['pin'] ?? null);
        } else {
            $r = FreeTrialRequest::findOrFail($owner->trial_request_id);
            if ($r->status === 'pending' && $actor->role === 'admin') {
                app(TrialApprovalService::class)->decide($actor, $r, 'approve', $r->version, 'Staff approved completion of the saved paired-market obligation.');
            }
        }

        return $this->resume($owner->id);
    }

    public function fence(CollectionAllocation $a): void
    {
        foreach (CollectionSubsidiaryObligation::where('allocation_id', $a->id)->whereNotIn('state', ['succeeded', 'already_active', 'canceled'])->lockForUpdate()->get() as $o) {
            if ($o->dependent_effect_id && CollectionBenefitEffect::whereKey($o->dependent_effect_id)->whereNotIn('state', ['prepared', 'needs_review', 'succeeded'])->exists()) {
                throw new CollectionException('subsidiary_confirmation_required_before_correction');
            }
            if ($o->trial_request_id) {
                $r = FreeTrialRequest::findOrFail($o->trial_request_id);
                app(SubsidiaryTrialWorkflow::class)->assertCancelable($r);
                app(SubsidiaryProfileCopyService::class)->cancelled($r);
                $r->update(['status' => 'canceled', 'active_identity' => null, 'version' => $r->version + 1]);
            }
            $o->update(['state' => 'canceled', 'version' => $o->version + 1, 'last_error_code' => 'source_purchase_corrected']);
        }
    }
}
