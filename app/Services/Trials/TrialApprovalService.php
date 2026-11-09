<?php

namespace App\Services\Trials;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\MarketTrialApprovalPolicy;
use App\Models\Product;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\WalletSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TrialApprovalService
{
    public function ready(): void
    {
        abort_unless(config('reconciliation.trials_enabled') && Schema::hasTable('free_trial_requests'), 503, 'Trial approval is unavailable. Ask an admin to apply the CRM workflow migration.');
    }

    public function access(User $user, int $market): void
    {
        $this->ready();
        abort_unless($user->isActive() && in_array($user->role, ['admin', 'sub_admin', 'sales', 'field_sales']), 403);
        app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($user, $market);
    }

    public function policy(int $market): array
    {
        $this->ready();
        $p = MarketTrialApprovalPolicy::find($market);

        return ['platform_id' => $market, 'approval_mode' => $p?->approval_mode ?? 'admin_only', 'version' => $p?->version ?? 1,
            'pin_exception_reason' => $p?->pin_exception_reason, 'review_on' => $p?->review_on,
            'pin_configured' => app(WalletSettingsService::class)->freeTrialPinIsConfigured()];
    }

    public function source(array $terms): string
    {
        $clients = Client::whereIn('id', $terms['client_ids'] ?? [])->orderBy('id')->get()->map->only(['id', 'platform_id', 'wp_post_id', 'wp_user_id', 'profile_removal_requested_at'])->all();
        $deal = ! empty($terms['source_deal_id']) ? Deal::find($terms['source_deal_id']) : null;
        $fields = ($terms['action'] ?? '') === 'subsidiary' ? ['id', 'platform_id', 'client_id', 'product_id'] : ['id', 'platform_id', 'client_id', 'product_id', 'status', 'duration_days', 'expires_at', 'activated_at'];
        $product = Product::find($terms['product_id'] ?? 0);

        $payload = ['clients' => $clients, 'deal' => $deal?->only($fields), 'product' => $product?->only(['id', 'platform_id', 'tier', 'is_active', 'is_archived'])];
        if (! empty($terms['subsidiary_identity'])) $payload['subsidiary_identity'] = app(\App\Services\SubsidiaryActivationPreviewService::class)->currentIdentity($terms);
        if ($facts = app(\App\Services\SubsidiaryPromotionPolicyService::class)->sourceFacts($terms)) {
            // Versioned paired terms: the purchased package and frozen copy snapshot are part of the approval.
            $payload['promotion'] = $facts + ['copy_snapshot_digest' => $terms['copy_snapshot_digest'] ?? null];
        }
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function create(User $actor, array $terms, ?string $pin = null, ?string $requestKey = null): FreeTrialRequest
    {
        $market = (int) $terms['platform_id'];
        $this->access($actor, $market);
        abort_if(request()->attributes->get('crm_impersonator'), 403, 'Exit impersonation before authorizing trials.');
        $reason = trim((string) ($terms['reason'] ?? ''));
        abort_if($reason === '', 422, 'Explain why this trial is needed.');
        $terms['client_ids'] = array_values(array_unique(array_map('intval', $terms['client_ids'] ?? [])));
        sort($terms['client_ids']);
        $product = Product::where('platform_id', $market)->where('is_active', true)->findOrFail($terms['product_id']);
        abort_if($product->is_archived, 422, 'This package is archived.');
        foreach ($terms['client_ids'] as $id) {
            abort_unless(Client::whereKey($id)->where('platform_id', $market)->exists(), 422, 'The advertiser does not belong to this market.');
        }
        $days = (int) ($terms['duration_days'] ?? 0);
        $limit = $terms['action'] === 'subsidiary' ? app(\App\Services\SubsidiaryPromotionPolicyService::class)->maxDays($terms) : ($terms['action'] === 'seo_boost' ? 90 : 365);
        abort_if($days < 1 || $days > $limit, 422, 'Trial duration is outside the allowed range.');
        $key = $requestKey ?: (string) Str::uuid();
        if (! empty($terms['subsidiary_identity'])) abort_unless(app(\App\Services\SubsidiaryActivationPreviewService::class)->same($terms['subsidiary_identity'], app(\App\Services\SubsidiaryActivationPreviewService::class)->currentIdentity($terms)), 409, 'Customer identity changed. Refresh the preview.');
        $source = $this->source($terms);
        $save = function (?int $generation = null) use ($actor, $terms, $market, $reason, $key, $source) {
            return DB::transaction(function () use ($actor, $terms, $market, $reason, $key, $source, $generation) {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $actor->refresh();
                $this->access($actor, $market);
                $policy = MarketTrialApprovalPolicy::firstOrCreate(['platform_id' => $market]);
                $policy = MarketTrialApprovalPolicy::whereKey($market)->lockForUpdate()->firstOrFail();
                if ($generation !== null) {
                    abort_unless($policy->approval_mode === 'admin_or_pin', 403, 'This market requires admin approval.');
                }
                foreach (FreeTrialRequest::where('platform_id', $market)->where('status', 'approved')->where('expires_at', '<', now())->get() as $expired) {
                    if ($expired->action === 'subsidiary' && app(\App\Services\SubsidiaryProfileCopyService::class)->holdsReservation($expired)) {
                        continue; // A bound copy keeps its identity reservation and waits for reauthorization.
                    }
                    if (! in_array($expired->activation_result_json['profile_resolution'] ?? '', ['resolving', 'unknown']) && ! \App\Models\TrialActivationOperation::where('request_id', $expired->id)->whereNotNull('dispatched_at')->exists()) {
                        $expired->update(['status' => 'expired', 'active_identity' => null]);
                    }
                }
                $prior = FreeTrialRequest::where('request_key', $key)->first();
                if ($prior) {
                    abort_unless($prior->requester_id === $actor->id && ($terms['action'] === 'subsidiary' ? app(\App\Services\SubsidiaryActivationPreviewService::class)->same($prior->terms_json, $terms) : $prior->terms_json === $terms), 409, 'This request key belongs to different terms.');

                    return $prior;
                }
                if ($terms['action'] === 'subsidiary') app(\App\Services\SubsidiaryTrialWorkflow::class)->reserve($terms);
                $duplicate = FreeTrialRequest::where('platform_id', $market)->where('action', $terms['action'])->whereIn('status', ['pending', 'approved', 'activating', 'uncertain'])->where('source_version', $source)->get()->first(fn ($r) => $r->terms_json['client_ids'] === $terms['client_ids']);
                abort_if((bool) $duplicate, 409, 'An unresolved request already exists for these trial terms. Open it in Reconciliations.');
                $approved = $actor->role === 'admin' || $generation !== null;
                $r = FreeTrialRequest::create(['request_key' => $key, 'active_identity' => $terms['action'] === 'subsidiary' ? app(\App\Services\SubsidiaryTrialWorkflow::class)->identityKey($terms) : hash('sha256', json_encode([$market, $terms['action'], $terms['client_ids'], $terms['source_deal_id'] ?? null])), 'requester_id' => $actor->id, 'platform_id' => $market, 'client_id' => count($terms['client_ids']) === 1 ? $terms['client_ids'][0] : null,
                    'deal_id' => $terms['source_deal_id'] ?? null, 'action' => $terms['action'], 'terms_json' => $terms, 'source_version' => $source, 'policy_version' => $policy->version,
                    'status' => $approved ? 'approved' : 'pending', 'approved_via' => $approved ? ($actor->role === 'admin' ? 'admin' : 'pin') : null, 'approver_id' => $actor->role === 'admin' ? $actor->id : null,
                    'authorized_actor_id' => $approved ? $actor->id : null, 'pin_generation' => $generation, 'reason' => $reason, 'authorized_at' => $approved ? now() : null, 'expires_at' => $approved ? now()->addHours(24) : null]);
                $this->event($r, $actor, $approved ? 'authorized' : 'requested', ['method' => $r->approved_via], $reason);
                if (! empty($terms['copy_contract_version'])) {
                    app(\App\Services\SubsidiaryProfileCopyService::class)->register($r);
                }

                return $r;
            });
        };
        if ($actor->role !== 'admin' && $pin !== null && $pin !== '') {
            $policy = $this->policy($market);
            abort_unless($policy['approval_mode'] === 'admin_or_pin', 403, 'This market requires admin approval.');
            $keys = ['trial-pin:staff:'.$actor->id.':'.$market, 'trial-pin:ip:'.hash('sha256', (string) request()->ip())];
            foreach ($keys as $k) {
                abort_if(RateLimiter::tooManyAttempts($k, 5), 429, 'Too many PIN attempts. Try again in 15 minutes.');
            }
            foreach ($keys as $k) {
                RateLimiter::hit($k, 900);
            }
            $r = app(WalletSettingsService::class)->authorizeFreeTrialPin($pin, fn ($generation) => $save($generation));
            foreach ($keys as $k) {
                RateLimiter::clear($k);
            }

            return $r;
        }

        return $save();
    }

    public function decide(User $actor, FreeTrialRequest $r, string $action, int $version, string $reason): FreeTrialRequest
    {
        $this->access($actor, $r->platform_id);
        abort_unless($actor->role === 'admin', 403, 'Only an admin can decide free-trial requests.');
        abort_if(request()->attributes->get('crm_impersonator'), 403);

        return DB::transaction(function () use ($actor, $r, $action, $version, $reason) {
            $r = FreeTrialRequest::lockForUpdate()->findOrFail($r->id);
            abort_unless($r->version === $version && $r->status === 'pending', 409, 'The trial request changed.');
            $requester = User::findOrFail($r->requester_id);
            $this->access($requester, $r->platform_id);
            $policy = MarketTrialApprovalPolicy::whereKey($r->platform_id)->lockForUpdate()->first();
            abort_unless($r->source_version === $this->source($r->terms_json), 409, 'Client or subscription terms changed. Submit a fresh request.');
            $r->update(['status' => $action === 'approve' ? 'approved' : 'denied', 'active_identity' => $action === 'approve' ? $r->active_identity : null, 'approver_id' => $actor->id, 'authorized_actor_id' => $actor->id, 'approved_via' => $action === 'approve' ? 'admin' : null,
                'policy_version' => $policy?->version ?? 1, 'authorized_at' => $action === 'approve' ? now() : null, 'expires_at' => $action === 'approve' ? now()->addHours(24) : null, 'decision_reason' => $reason, 'version' => $r->version + 1]);
            $this->event($r, $actor, $action, [], $reason);

            return $r;
        });
    }

    public function assertDispatch(FreeTrialRequest $r): void
    {
        if ($r->action === 'subsidiary') {
            $primary = app(\App\Services\SubsidiaryTrialWorkflow::class)->assertAttached($r);
            abort_unless($primary->status === 'active' && (! $primary->expires_at || $primary->expires_at->isFuture()), 409, 'Primary coverage must be current before a new subsidiary trial.');
        }
        abort_unless(in_array($r->status, ['approved', 'activating']), 409, 'This trial is not authorized for activation.');
        $actor = User::findOrFail($r->authorized_actor_id);
        $this->access($actor, $r->platform_id);
        $this->access(User::findOrFail($r->requester_id), $r->platform_id);
        abort_unless($r->expires_at && $r->expires_at->isFuture(), 409, 'Trial authorization expired. Request a new decision.');
        if ($r->approved_via === 'pin') {
            app(WalletSettingsService::class)->assertFreeTrialPinGeneration((int) $r->pin_generation);
        }
        $policy = MarketTrialApprovalPolicy::whereKey($r->platform_id)->lockForUpdate()->first();
        abort_unless(($policy?->version ?? 1) === $r->policy_version, 409, 'Market approval policy changed. Request authorization again.');
        if ($r->approved_via === 'admin') {
            abort_unless($actor->role === 'admin', 403);
        } else {
            abort_unless($policy?->approval_mode === 'admin_or_pin', 409, 'PIN approval is no longer allowed for this market.');
            app(WalletSettingsService::class)->assertFreeTrialPinGeneration((int) $r->pin_generation);
        }
        abort_unless(hash_equals($r->source_version, $this->source($r->terms_json)), 409, 'Client or subscription changed after approval.');
        abort_unless(app(\App\Services\SubsidiaryPromotionPolicyService::class)->stillAllowed($r->terms_json), 409, 'Paired-market settings no longer allow these terms. Request authorization again.');
    }

    /**
     * Renews an expired authorization for a held profile copy on the same immutable
     * terms. Preserves the run, completed items, provision identity and reservation.
     */
    public function reauthorizeCopy(User $actor, FreeTrialRequest $r, int $requestVersion, int $runVersion, string $reason, ?string $pin = null): FreeTrialRequest
    {
        $copies = app(\App\Services\SubsidiaryProfileCopyService::class);
        abort_unless($r->action === 'subsidiary' && $copies->tagged($r), 422, 'Only paired profile setups can be reauthorized here.');
        $this->access($actor, (int) $r->platform_id);
        abort_if(request()->attributes->get('crm_impersonator'), 403, 'Exit impersonation before authorizing trials.');
        abort_unless($actor->role === 'admin' || (int) $r->requester_id === (int) $actor->id, 403);
        $reason = trim($reason);
        abort_if($reason === '', 422, 'Explain why setup should continue.');
        $renew = function (?int $generation = null) use ($actor, $r, $requestVersion, $runVersion, $reason, $copies) {
            return DB::transaction(function () use ($actor, $r, $requestVersion, $runVersion, $reason, $copies, $generation) {
                $r = FreeTrialRequest::lockForUpdate()->findOrFail($r->id);
                $run = \App\Models\SubsidiaryProfileCopyRun::lockForUpdate()->where('request_id', $r->id)->firstOrFail();
                abort_unless($r->version === $requestVersion && $run->version === $runVersion, 409, 'Profile setup changed. Reload it before continuing.');
                abort_unless(in_array($r->status, ['approved', 'activating'], true) && $run->state === 'needs_review' && $run->error_code === 'approval_expired', 409, 'This setup does not need reauthorization.');
                abort_if($run->items()->whereIn('state', ['dispatching', 'uncertain'])->exists(), 409, 'Check the unconfirmed setup step before reauthorizing.');
                abort_unless($run->snapshot_digest === ($r->terms_json['copy_snapshot_digest'] ?? null) && $copies->digest($run->snapshot_json) === $run->snapshot_digest, 409, 'The approved profile snapshot changed. Cancel and review a new setup.');
                $main = app(\App\Services\SubsidiaryTrialWorkflow::class)->assertAttached($r);
                $this->access($actor, (int) $main->platform_id);
                abort_unless($main->status === 'active' && (! $main->expires_at || $main->expires_at->isFuture()), 422, 'The primary subscription must be active before setup can continue.');
                abort_unless(hash_equals($r->source_version, $this->source($r->terms_json)), 409, 'Client or subscription changed. Cancel and review a new setup.');
                abort_unless(app(\App\Services\SubsidiaryPromotionPolicyService::class)->stillAllowed($r->terms_json), 422, 'Paired-market settings no longer allow these terms.');
                abort_unless((int) $r->terms_json['duration_days'] <= app(\App\Services\SubsidiaryPromotionPolicyService::class)->maxDays($r->terms_json), 422, 'The approved number of days is no longer allowed.');
                $policy = MarketTrialApprovalPolicy::firstOrCreate(['platform_id' => $r->platform_id]);
                $policy = MarketTrialApprovalPolicy::whereKey($r->platform_id)->lockForUpdate()->firstOrFail();
                if ($generation !== null) {
                    abort_unless($policy->approval_mode === 'admin_or_pin', 403, 'This market requires admin approval.');
                } else {
                    abort_unless($actor->role === 'admin', 403, 'Enter the approval PIN or ask an admin to continue setup.');
                }
                $r->update(['authorized_actor_id' => $actor->id, 'approved_via' => $actor->role === 'admin' ? 'admin' : 'pin', 'approver_id' => $actor->role === 'admin' ? $actor->id : null,
                    'pin_generation' => $generation, 'policy_version' => $policy->version, 'authorized_at' => now(), 'expires_at' => now()->addHours(24), 'version' => $r->version + 1]);
                $run->update(['state' => 'copying', 'error_code' => null, 'version' => $run->version + 1]);
                $this->event($r, $actor, 'profile_copy_reauthorized', ['run_id' => $run->id, 'method' => $r->approved_via], $reason);

                return $r->fresh();
            });
        };
        if ($actor->role !== 'admin') {
            abort_unless($pin !== null && $pin !== '' && $this->policy((int) $r->platform_id)['approval_mode'] === 'admin_or_pin', 403, 'This market requires admin approval.');
            $keys = ['trial-pin:staff:'.$actor->id.':'.$r->platform_id, 'trial-pin:ip:'.hash('sha256', (string) request()->ip())];
            foreach ($keys as $k) {
                abort_if(RateLimiter::tooManyAttempts($k, 5), 429, 'Too many PIN attempts. Try again in 15 minutes.');
            }
            foreach ($keys as $k) {
                RateLimiter::hit($k, 900);
            }
            $renewed = app(WalletSettingsService::class)->authorizeFreeTrialPin($pin, fn ($generation) => $renew($generation));
            foreach ($keys as $k) {
                RateLimiter::clear($k);
            }
        } else {
            $renewed = $renew();
        }
        $run = \App\Models\SubsidiaryProfileCopyRun::where('request_id', $renewed->id)->first();
        \App\Jobs\ProcessSubsidiaryProfileCopy::dispatch($run->id);

        return $renewed->fresh();
    }

    public function event(FreeTrialRequest $r, ?User $actor, string $action, array $detail = [], ?string $reason = null): void
    {
        DB::table('free_trial_request_events')->insert(['request_id' => $r->id, 'actor_id' => $actor?->id, 'action' => $action, 'detail_json' => json_encode($detail), 'reason' => $reason, 'created_at' => now()]);
        abort_unless(app(AuditService::class)->record(['platform_id' => $r->platform_id, 'actor_id' => $actor?->id, 'entity_type' => 'free_trial_request', 'entity_id' => $r->id, 'action' => 'trial_approval_'.$action, 'after_state' => $detail, 'reason' => $reason]), 500, 'Trial audit could not be saved.');
    }
}
