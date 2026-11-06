<?php

namespace App\Services\Trials;

use App\Models\Client;
use App\Models\Deal;
use App\Models\FreeTrialRequest;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\SeoBoostItem;
use App\Models\TimelineEvent;
use App\Models\TrialActivationOperation;
use App\Models\User;
use App\Services\ClientLifecycleMutationLock;
use App\Services\WpSyncService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A durable, single-send adapter. A lost response is held, never retried as a new grant. */
class TrialActivationService
{
    public function __construct(private readonly TrialApprovalService $approvals) {}

    public function execute(FreeTrialRequest $request): FreeTrialRequest
    {
        $r = $request->fresh();
        if (! in_array($r->status, ['approved', 'activating'])) {
            return $r;
        }
        abort_if(DB::transactionLevel() > 0, 409, 'Commit the trial authorization before activation.');
        if ($r->action === 'subsidiary') {
            $r = app(\App\Services\SubsidiaryTrialWorkflow::class)->prepare($r);
            $primary = Deal::findOrFail($r->terms_json['source_deal_id']);
            if ($r->status === 'uncertain' || ! $r->client_id || $primary->status !== 'active' || ($primary->expires_at && $primary->expires_at->isPast())) return $r;
            $preview = app(\App\Services\SubsidiaryTrialService::class)->previewForDeal(Deal::findOrFail($r->terms_json['source_deal_id']), Platform::findOrFail($r->platform_id), $r->client_id);
            abort_if((bool) $preview['config_errors'], 409, 'Subsidiary trial configuration changed.');
        }
        if ($r->action === 'seo_boost') {
            $terms = $r->terms_json;
            if (empty($r->activation_result_json['batch_id'])) {
                DB::transaction(function () use ($r) {
                    $r = FreeTrialRequest::lockForUpdate()->findOrFail($r->id);
                    $this->approvals->assertDispatch($r);
                });
                $result = app(\App\Services\SeoBoostService::class)->createBatch($r->platform_id, $r->requester_id, $terms['product_id'], $terms['product_price_id'] ?? null, $terms['duration_days'], $terms['targets'], $terms['client_ids'], $r->reason, $r->id);
                $r->update(['activation_result_json' => ['batch_id' => $result['batch']->id]]);
            } else {
                app(\App\Services\SeoBoostService::class)->resumeApprovedBatch((int) $r->activation_result_json['batch_id'], $r);
            }
        } else {
            $client = Client::findOrFail($r->client_id);
            $this->runItem($r, $client, 'client:'.$client->id);
        }

        return $this->summarize($r);
    }

    public function runItem(FreeTrialRequest $request, Client $client, string $itemKey, ?int $seoItem = null): ?Deal
    {
        abort_if(DB::transactionLevel() > 0, 409, 'Trial activation cannot run inside a database transaction.');

        return app(ClientLifecycleMutationLock::class)->run($client, function () use ($request, $client, $itemKey, $seoItem) {
            $r = $request->fresh();
            $client->refresh();
            $existing = TrialActivationOperation::where('request_id', $r->id)->where('item_key', $itemKey)->first();
            if ($existing && $existing->state === 'succeeded') {
                return Deal::find($existing->deal_id);
            }
            if ($existing && in_array($existing->state, ['dispatching', 'uncertain'])) {
                if ($existing->response_json) {
                    return $this->verifyAndFinalize($existing, $r, $client, $seoItem);
                }

                return null;
            }
            $sync = WpSyncService::forPlatform($r->platform_id);
            $profile = null;
            if ($r->action === 'subsidiary') {
                $profile = $sync->getClient((int) $client->wp_post_id);
                app(\App\Services\SubsidiaryTrialWorkflow::class)->assertSiteIdentity($r, $client, $profile);
                if (DB::transaction(fn () => app(\App\Services\SubsidiaryTrialWorkflow::class)->noGrant($r, $client))) return null;
            }
            $this->eligibility($r, $client);
            $baseline = $this->safeProfile($profile ?? $sync->getClient((int) $client->wp_post_id));
            abort_unless($baseline['wp_post_id'] === (int) $client->wp_post_id && $baseline['wp_user_id'] === (int) $client->wp_user_id, 409, 'The site profile identity changed.');
            $op = DB::transaction(function () use ($r, $client, $itemKey, $baseline, $seoItem) {
                $r = FreeTrialRequest::lockForUpdate()->findOrFail($r->id);
                $this->approvals->assertDispatch($r);
                abort_unless(in_array((int) $client->id, array_map('intval', $r->terms_json['client_ids']), true), 409, 'This advertiser is not in the frozen trial authorization.');
                $op = TrialActivationOperation::firstOrCreate(['request_id' => $r->id, 'item_key' => $itemKey], [
                    'operation_key' => (string) Str::uuid(), 'platform_id' => $r->platform_id, 'client_id' => $client->id,
                    'command_json' => ['post_id' => (int) $client->wp_post_id, 'wp_user_id' => (int) $client->wp_user_id, 'product_id' => (int) $r->terms_json['product_id'], 'duration_days' => (int) $r->terms_json['duration_days'],
                        'method' => $r->action === 'extend' ? 'extend' : 'activate', 'tier' => $this->tier(Product::findOrFail($r->terms_json['product_id'])), 'seo_item_id' => $seoItem],
                    'baseline_json' => $baseline, 'source_digest' => $r->source_version, 'state' => 'prepared']);
                $op = TrialActivationOperation::lockForUpdate()->findOrFail($op->id);
                abort_unless($op->state === 'prepared', 409, 'This trial operation has already been dispatched.');
                $terms = $r->terms_json;
                $source = ! empty($terms['source_deal_id']) ? Deal::findOrFail($terms['source_deal_id']) : null;
                if (! $op->deal_id) {
                    // Renewals/extensions create separate zero-cash history; paid deal attribution is preserved.
                    $deal = $r->action === 'activate' ? $source : Deal::create(['platform_id' => $r->platform_id, 'client_id' => $client->id, 'product_id' => $terms['product_id'],
                        'product_price_id' => $terms['product_price_id'] ?? null, 'plan_type' => $this->tier(Product::findOrFail($terms['product_id'])),
                        'amount' => 0, 'currency' => $client->platform?->currency_code ?: 'KES', 'duration' => 'weekly', 'duration_days' => $terms['duration_days'], 'status' => 'pending',
                        'assigned_to' => $r->requester_id, 'origin' => $r->action === 'seo_boost' ? 'seo_boost' : 'manual', 'seo_boost_batch_id' => $seoItem ? SeoBoostItem::find($seoItem)?->batch_id : null]);
                    abort_unless($deal && ! $deal->payment_id && ! $deal->activated_at, 409, 'This source deal already has financial or activation history.');
                    $op->deal_id = $deal->id;
                }
                $op->fill(['state' => 'dispatching', 'claimed_by' => (string) Str::uuid(), 'dispatched_at' => now(), 'version' => $op->version + 1]);
                $op->save();
                $r->update(['status' => 'activating', 'version' => $r->version + 1]);
                $this->approvals->event($r, User::find($r->authorized_actor_id), 'dispatch_claimed', ['operation_id' => $op->operation_key, 'client_id' => $client->id]);

                return $op;
            });
            try {
                $cmd = $op->command_json;
                $result = $cmd['method'] === 'extend' ? $sync->extendClient($cmd['post_id'], $cmd['duration_days']) : $sync->activateClient($cmd['post_id'], (string) $cmd['tier'], $cmd['duration_days'], $op->deal_id);
                $safe = array_intersect_key($result, array_flip(['success', 'escort_expire', 'new_expire', 'post_id', 'wp_post_id', 'crm_deal_id', 'operation_id']));
                $op->update(['response_json' => $safe]);

                return $this->verifyAndFinalize($op->fresh(), $r, $client, $seoItem);
            } catch (\Throwable $e) {
                $op->update(['state' => 'uncertain', 'error_code' => 'activation_outcome_unverified', 'version' => $op->version + 1]);
                $this->summarize($r);

                return null;
            }
        });
    }

    private function eligibility(FreeTrialRequest $r, Client $c): void
    {
        abort_unless((int) $c->platform_id === (int) $r->platform_id && $c->wp_post_id > 0 && $c->wp_user_id > 0, 422, 'Advertiser is not linked to the selected market site.');
        abort_if($c->profile_removal_requested_at || $c->profile_reverted_at, 422, 'A removed profile must be reviewed before a free trial.');
        $source = $r->terms_json['source_deal_id'] ?? null;
        $active = Deal::where('client_id', $c->id)->where('is_free_trial', true)->where('status', 'active')->where('expires_at', '>', now());
        if ($r->action === 'extend' && $source) {
            $active->where('id', '!=', $source);
        }
        abort_if($active->exists(), 422, 'This advertiser already has an active free trial.');
        if ($r->action !== 'extend') {
            abort_if(Deal::where('client_id', $c->id)->where('status', 'active')->where('expires_at', '>', now())->exists(), 422, 'A trial cannot replace existing active coverage.');
        }
        $rule = app(\App\Billing\Repositories\BillingConfigurationRepository::class)->subscriptionRuleForMarket($r->platform_id);
        if ($rule && $r->action !== 'field_trial') {
            abort_unless((bool) data_get($rule->free_trial_json, 'enabled', false), 422, 'Free trials are disabled for this market.');
        }
        if ($r->action === 'field_trial') {
            $p = Platform::findOrFail($r->platform_id);
            abort_unless((int) $p->field_trial_product_id === (int) $r->terms_json['product_id'] && max(1, (int) ($p->field_trial_duration_days ?: 7)) === (int) $r->terms_json['duration_days'], 409, 'Field trial configuration changed.');
            $actor = User::findOrFail($r->requester_id);
            $req = \Illuminate\Http\Request::create('/', 'GET');
            $req->setUserResolver(fn () => $actor);
            $deposit = app(\App\Http\Controllers\CRM\FieldSalesController::class)->depositStatus($req, $c)->getData(true);
            abort_unless(! empty($deposit['received']), 422, 'Activation deposit has not been received yet.');
        }
    }

    public function recover(FreeTrialRequest $r): FreeTrialRequest
    {
        if ($r->action === 'subsidiary' && in_array($r->activation_result_json['profile_resolution'] ?? '', ['resolving', 'unknown'])) return app(\App\Services\SubsidiaryTrialWorkflow::class)->recoverProfile($r);
        if ($r->status === 'canceled') return $r;
        // Recovery reads the already-sent operation and may replay local bookkeeping only.
        foreach (TrialActivationOperation::where('request_id', $r->id)->whereIn('state', ['dispatching', 'uncertain'])->get() as $op) {
            if (! $op->response_json) {
                continue;
            }
            $c = Client::findOrFail($op->client_id);
            app(ClientLifecycleMutationLock::class)->run($c, fn () => $this->verifyAndFinalize($op, $r, $c, $op->command_json['seo_item_id'] ?? null));
        }

        return $this->summarize($r);
    }

    private function verifyAndFinalize(TrialActivationOperation $op, FreeTrialRequest $r, Client $c, ?int $seoItem): ?Deal
    {
        if ((int) $c->platform_id !== (int) $op->platform_id || (int) $c->wp_post_id !== (int) $op->command_json['post_id'] || (int) $c->wp_user_id !== (int) $op->command_json['wp_user_id']) {
            return $this->hold($op);
        }
        $response = $op->response_json ?? [];
        $expiry = (int) ($response['escort_expire'] ?? $response['new_expire'] ?? 0);
        if ($expiry <= now()->timestamp || $expiry > now()->addYears(3)->timestamp || ($response['success'] ?? true) === false) {
            return $this->hold($op);
        }
        $rawProfile = WpSyncService::forPlatform($op->platform_id)->getClient((int) $op->command_json['post_id']);
        if ($r->action === 'subsidiary') {
            try { app(\App\Services\SubsidiaryTrialWorkflow::class)->assertSiteIdentity($r, $c, $rawProfile); }
            catch (\Throwable $e) { return $this->hold($op); }
        }
        $profile = $this->safeProfile($rawProfile);
        if ($profile['wp_post_id'] !== (int) $op->command_json['post_id'] || $profile['wp_user_id'] !== (int) $op->command_json['wp_user_id'] || $profile['escort_expire'] !== $expiry || $profile['post_status'] !== 'publish') {
            return $this->hold($op);
        }

        return DB::transaction(function () use ($op, $r, $c, $profile, $expiry, $seoItem) {
            $op = TrialActivationOperation::lockForUpdate()->findOrFail($op->id);
            if ($op->state === 'succeeded') {
                return Deal::findOrFail($op->deal_id);
            }
            $deal = Deal::lockForUpdate()->findOrFail($op->deal_id);
            $payment = $deal->payment_id ? Payment::findOrFail($deal->payment_id) : Payment::create([
                'platform_id' => $r->platform_id, 'client_id' => $c->id, 'deal_id' => $deal->id, 'product_id' => $deal->product_id, 'amount' => 0, 'currency' => $deal->currency,
                'transaction_uuid' => $op->operation_key, 'transaction_reference' => 'TRIAL-'.$r->id.'-'.$op->id, 'status' => 'completed', 'purpose' => 'subscription', 'source' => 'free_trial',
                'confirmed_by' => $r->authorized_actor_id, 'confirmed_at' => now(), 'completed_at' => now(), 'start_date' => now(), 'end_date' => Carbon::createFromTimestamp($expiry, 'UTC'),
                'payment_data' => ['trial_request_id' => $r->id, 'trial_operation_id' => $op->operation_key, 'approval_method' => $r->approved_via]]);
            $deal->update(['payment_id' => $payment->id, 'status' => 'active', 'activated_at' => $op->dispatched_at, 'expires_at' => Carbon::createFromTimestamp($expiry, 'UTC'),
                'amount' => 0, 'original_amount' => $deal->original_amount ?? $deal->amount, 'duration_days' => $op->command_json['duration_days'], 'is_free_trial' => true,
                'free_trial_approved_by' => $r->approved_via === 'pin' ? 'PIN · staff '.$r->authorized_actor_id : 'Admin · '.$r->authorized_actor_id,
                'activated_by_field_agent' => $r->action === 'field_trial' ? $r->requester_id : $deal->activated_by_field_agent]);
            Client::whereKey($c->id)->update(['profile_status' => $profile['post_status'], 'escort_expire' => $expiry] + array_intersect_key($profile, array_flip(['premium', 'premium_expire', 'featured', 'featured_expire', 'needs_payment', 'notactive'])));
            app(\App\Services\SubscriptionActivationAuditService::class)->record($deal, $payment, (int) $r->authorized_actor_id, 'free_trial', 'trial_approval', $r->reason);
            if ($r->action === 'subsidiary' && $main = Deal::find($r->terms_json['source_deal_id'])) {
                $main->update(['linked_deal_id' => $deal->id, 'pending_subsidiary_trial' => array_merge($main->pending_subsidiary_trial ?? [], ['status' => 'linked', 'trial_request_id' => $r->id, 'subsidiary_deal_id' => $deal->id])]);
                $deal->update(['linked_deal_id' => $main->id]);
            }
            if ($seoItem) {
                SeoBoostItem::whereKey($seoItem)->update(['deal_id' => $deal->id, 'status' => 'active', 'activated_at' => $deal->activated_at, 'expires_at' => $deal->expires_at, 'failure_reason' => null]);
            }
            $op->update(['state' => 'succeeded', 'verified_effect_json' => $profile, 'verified_at' => now(), 'finalized_at' => now(), 'error_code' => null, 'version' => $op->version + 1]);
            TimelineEvent::create(['platform_id' => $r->platform_id, 'entity_type' => 'client', 'entity_id' => $c->id, 'event_type' => 'free_trial_approved_activation', 'actor_id' => $r->authorized_actor_id, 'content' => ['deal_id' => $deal->id, 'trial_request_id' => $r->id, 'operation_id' => $op->operation_key, 'approval_method' => $r->approved_via, 'expires_at' => $deal->expires_at->toIso8601String()], 'created_at' => now()]);
            $this->approvals->event($r, User::find($r->authorized_actor_id), 'activation_verified', ['operation_id' => $op->operation_key, 'deal_id' => $deal->id]);

            return $deal;
        });
    }

    private function hold(TrialActivationOperation $op): ?Deal
    {
        $op->update(['state' => 'uncertain', 'error_code' => 'readback_not_proven']);

        return null;
    }

    public function summarize(FreeTrialRequest $r): FreeTrialRequest
    {
        $r->refresh();
        if (in_array($r->status, ['canceled', 'denied', 'expired']) || ($r->action === 'subsidiary' && in_array($r->activation_result_json['profile_resolution'] ?? '', ['resolving', 'unknown']))) return $r;
        $ops = TrialActivationOperation::where('request_id', $r->id)->get();
        $expected = count($r->terms_json['client_ids']);
        $status = $ops->contains(fn ($o) => in_array($o->state, ['dispatching', 'uncertain'])) ? 'uncertain' : ($expected > 0 && $ops->count() === $expected && $ops->every(fn ($o) => $o->state === 'succeeded') ? 'active' : ($ops->isNotEmpty() ? 'activating' : $r->fresh()->status));
        $r->refresh();
        $r->update(['status' => $status, 'version' => $r->version + 1, 'active_identity' => $status === 'active' ? null : $r->active_identity]);

        return $r->fresh();
    }

    private function tier(Product $p): string
    {
        $candidate = strtolower((string) ($p->tier ?: $p->name));

        return str_contains($candidate, 'vip') ? 'vip' : (str_contains($candidate, 'premium') ? 'premium' : 'basic');
    }

    private function safeProfile(array $p): array
    {
        $safe = ['wp_post_id' => (int) ($p['wp_post_id'] ?? 0), 'wp_user_id' => (int) ($p['wp_user_id'] ?? 0), 'post_status' => (string) ($p['post_status'] ?? ''),
            'escort_expire' => (int) ($p['escort_expire'] ?? $p['agency_expire'] ?? 0)];
        foreach (['premium', 'featured', 'needs_payment', 'notactive'] as $key) {
            if (array_key_exists($key, $p)) {
                $safe[$key] = (bool) $p[$key];
            }
        }
        foreach (['premium_expire', 'featured_expire'] as $key) {
            if (array_key_exists($key, $p)) {
                $safe[$key] = (int) $p[$key];
            }
        }

        return $safe;
    }
}
