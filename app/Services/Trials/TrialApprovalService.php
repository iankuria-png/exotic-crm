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

        return hash('sha256', json_encode(['clients' => $clients, 'deal' => $deal?->only($fields), 'product' => $product?->only(['id', 'platform_id', 'tier', 'is_active', 'is_archived'])], JSON_THROW_ON_ERROR));
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
        abort_if($days < 1 || $days > 365 || (in_array($terms['action'], ['subsidiary', 'seo_boost']) && $days > 90), 422, 'Trial duration is outside the allowed range.');
        $key = $requestKey ?: (string) Str::uuid();
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
                FreeTrialRequest::where('platform_id', $market)->where('status', 'approved')->where('expires_at', '<', now())->update(['status' => 'expired', 'active_identity' => null]);
                $prior = FreeTrialRequest::where('request_key', $key)->first();
                if ($prior) {
                    abort_unless($prior->requester_id === $actor->id && $prior->terms_json === $terms, 409, 'This request key belongs to different terms.');

                    return $prior;
                }
                $duplicate = FreeTrialRequest::where('platform_id', $market)->where('action', $terms['action'])->whereIn('status', ['pending', 'approved', 'activating', 'uncertain'])->where('source_version', $source)->get()->first(fn ($r) => $r->terms_json['client_ids'] === $terms['client_ids']);
                abort_if((bool) $duplicate, 409, 'An unresolved request already exists for these trial terms. Open it in Reconciliations.');
                $approved = $actor->role === 'admin' || $generation !== null;
                $r = FreeTrialRequest::create(['request_key' => $key, 'active_identity' => hash('sha256', json_encode([$market, $terms['action'], $terms['client_ids'], $terms['source_deal_id'] ?? null])), 'requester_id' => $actor->id, 'platform_id' => $market, 'client_id' => count($terms['client_ids']) === 1 ? $terms['client_ids'][0] : null,
                    'deal_id' => $terms['source_deal_id'] ?? null, 'action' => $terms['action'], 'terms_json' => $terms, 'source_version' => $source, 'policy_version' => $policy->version,
                    'status' => $approved ? 'approved' : 'pending', 'approved_via' => $approved ? ($actor->role === 'admin' ? 'admin' : 'pin') : null, 'approver_id' => $actor->role === 'admin' ? $actor->id : null,
                    'authorized_actor_id' => $approved ? $actor->id : null, 'pin_generation' => $generation, 'reason' => $reason, 'authorized_at' => $approved ? now() : null, 'expires_at' => $approved ? now()->addHours(24) : null]);
                $this->event($r, $actor, $approved ? 'authorized' : 'requested', ['method' => $r->approved_via], $reason);

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
    }

    public function event(FreeTrialRequest $r, ?User $actor, string $action, array $detail = [], ?string $reason = null): void
    {
        DB::table('free_trial_request_events')->insert(['request_id' => $r->id, 'actor_id' => $actor?->id, 'action' => $action, 'detail_json' => json_encode($detail), 'reason' => $reason, 'created_at' => now()]);
        abort_unless(app(AuditService::class)->record(['platform_id' => $r->platform_id, 'actor_id' => $actor?->id, 'entity_type' => 'free_trial_request', 'entity_id' => $r->id, 'action' => 'trial_approval_'.$action, 'after_state' => $detail, 'reason' => $reason]), 500, 'Trial audit could not be saved.');
    }
}
