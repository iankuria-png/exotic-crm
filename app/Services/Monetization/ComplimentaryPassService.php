<?php

namespace App\Services\Monetization;

use App\Jobs\ProcessMonetizationAutomationItem;
use App\Models\Client;
use App\Models\ClientMonetizationPass;
use App\Models\Deal;
use App\Models\MonetizationAutomationItem;
use App\Models\MonetizationAutomationRun;
use App\Models\Platform;
use App\Models\PremiumContentEvent;
use App\Services\MonetizationSettingsService;
use App\Services\SubscriptionLifecycleService;
use App\Support\ClientLifecycleState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One idempotent path for free selling passes: future new subscriptions, all current
 * active subscriptions, and selected escorts. Free time always queues after any paid or
 * complimentary time the escort already owns; an existing pass is never rewritten.
 */
class ComplimentaryPassService
{
    public function __construct(private MonetizationSettingsService $settings) {}

    /**
     * @return array{status: string, pass: ClientMonetizationPass|null}
     */
    public function grant(Client $client, string $durationKey, string $source, string $sourceKey, ?int $dealId = null, ?int $runId = null, ?int $actorId = null): array
    {
        $result = DB::transaction(function () use ($client, $durationKey, $source, $sourceKey, $dealId, $runId, $actorId) {
            $client = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $hash = hash('sha256', 'complimentary:'.$source.':'.$sourceKey);
            if ($old = ClientMonetizationPass::where('idempotency_key_hash', $hash)->first()) {
                return ['status' => 'already_granted', 'pass' => $old];
            }
            if (ClientMonetizationPass::where('client_id', $client->id)->whereIn('status', ['held', 'revoked'])->where('expires_at', '>', now())->exists()) {
                return ['status' => 'held', 'pass' => null];
            }
            // Active-subscription batches never stack: an unclaimed or unexpired batch pass covers the escort.
            if ($source === 'campaign_active_subscription' && ($covering = ClientMonetizationPass::where('client_id', $client->id)->where('grant_source', $source)->where(fn ($q) => $q->where('status', 'granted')->orWhere(fn ($live) => $live->whereIn('status', ['active', 'queued'])->where('expires_at', '>', now())))->first())) {
                return ['status' => 'already_granted', 'pass' => $covering];
            }
            $s = $this->settings->forPlatform($client->platform);
            $price = $s->prices()->where('duration_key', $durationKey)->first();
            $days = (int) ($price?->duration_days ?: ($durationKey === '2_weeks' ? 14 : 30));
            $list = number_format((float) ($price?->price ?? 0), 2, '.', '');
            // The escort claims the pass at the end of the private-content journey; its term is
            // placed after any owned time only then (see PassService::claim). Until claimed it sells nothing.
            $pass = ClientMonetizationPass::create([
                'client_id' => $client->id, 'platform_id' => $client->platform_id, 'price_id' => $price?->id,
                'status' => 'granted', 'active_marker' => null,
                'starts_at' => now(), 'expires_at' => now()->addDays($days), 'duration_key' => $durationKey, 'duration_days' => $days,
                'currency' => $s->currency, 'list_amount' => $list, 'subsidy_amount' => $list, 'paid_amount' => 0,
                'eligibility_snapshot_json' => ['complimentary' => true, 'grant_source' => $source, 'source_key' => $sourceKey, 'actor_id' => $actorId],
                'is_sandbox' => $s->rollout_mode === 'sandbox', 'idempotency_key_hash' => $hash,
                'grant_source' => $source, 'source_deal_id' => $dealId, 'grant_run_id' => $runId,
            ]);
            PremiumContentEvent::create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'actor_id' => $actorId, 'kind' => 'free_pass_granted', 'reason' => 'You have a free '.($days === 14 ? '2-week' : $days.'-day').' selling pass to claim in Private content.', 'metadata_json' => ['pass_id' => $pass->id, 'grant_source' => $source, 'source_key' => $sourceKey]]);

            return ['status' => 'granted', 'pass' => $pass];
        }, 3);
        if ($result['status'] === 'granted') {
            try {
                app(SyncService::class)->profile($client);
            } catch (Throwable $e) {
                Log::warning('Free pass granted; profile sync deferred.', ['client_id' => $client->id, 'error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /** Grant the configured pass once for a genuinely new subscription. Never throws. */
    public function grantForNewSubscription(int $dealId): ?string
    {
        try {
            $deal = Deal::with('client.platform')->find($dealId);
            if (! $deal || ! $deal->client || $deal->status !== 'active' || $deal->is_free_trial || str_starts_with((string) $deal->origin, 'seo_boost')) {
                return null;
            }
            if ($deal->subscription_lifecycle !== SubscriptionLifecycleService::LIFECYCLE_NEW) {
                return null;
            }
            // Read-only lookup: activation in a market without Monetize must not create settings.
            $s = \App\Models\ContentMonetizationSetting::where('platform_id', $deal->client->platform_id)->first();
            if (! $s) {
                return null;
            }
            $policy = $this->settings->freePassPolicy($s);
            if (! $policy['new_subscriptions_enabled'] || ! $this->settings->runtime($s)['enabled']) {
                return null;
            }
            if ($policy['effective_from'] && $deal->activated_at && $deal->activated_at->lt(\Carbon\Carbon::parse($policy['effective_from']))) {
                return null;
            }

            return $this->grant($deal->client, $policy['duration_key'], 'policy_new_subscription', 'deal:'.$deal->id, $deal->id)['status'];
        } catch (Throwable $e) {
            Log::warning('New-subscription free pass failed; subscription activation stands.', ['deal_id' => $dealId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Escorts with a current active subscription in the market. */
    public function activeSubscriptions(Platform $platform): Builder
    {
        return Client::query()->where('platform_id', $platform->id)->where('client_type', 'escort')->active()
            ->where(fn ($q) => $q->whereNull('lifecycle_state')->orWhere('lifecycle_state', ClientLifecycleState::ACTIVE))
            ->where(function ($q) {
                $q->whereHas('deals', fn ($d) => $d->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '>', now()))
                    ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('deals')->where('escort_expire', '>', now()->timestamp));
            });
    }

    /**
     * Active subscribers still to receive an active-subscription batch pass: no unexpired
     * batch pass yet and not waiting in a batch that is still processing. Oldest client first.
     */
    public function activeSubscriptionsRemaining(Platform $platform): Builder
    {
        return $this->activeSubscriptions($platform)
            ->whereNotIn('id', ClientMonetizationPass::where('platform_id', $platform->id)->where('grant_source', 'campaign_active_subscription')->where(fn ($q) => $q->where('status', 'granted')->orWhere(fn ($live) => $live->whereIn('status', ['active', 'queued'])->where('expires_at', '>', now())))->whereNotNull('client_id')->select('client_id'))
            ->whereNotIn('id', MonetizationAutomationItem::where('platform_id', $platform->id)->where('kind', 'pass_grant')->whereIn('status', ['queued', 'running'])->whereNotNull('client_id')->whereHas('run', fn ($r) => $r->where('kind', 'active_pass_grant'))->select('client_id'))
            ->orderBy('id');
    }

    public function startCampaign(Platform $platform, string $scope, string $durationKey, array $clientIds, int $actor, ?int $batchSize = null): MonetizationAutomationRun
    {
        $s = $this->settings->forPlatform($platform);
        abort_unless($this->settings->runtime($s)['enabled'], 409, 'Private content must be enabled in this market before granting passes.');
        abort_unless(in_array($durationKey, ['2_weeks', '1_month'], true), 422, 'Choose a two-week or one-month pass.');
        abort_unless($batchSize === null || in_array($batchSize, [50, 100, 150], true), 422, 'Choose a batch of 50, 100 or 150 escorts.');
        if ($scope === 'selected') {
            $ids = collect($clientIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
            abort_if($ids->isEmpty(), 422, 'Select at least one escort.');
            abort_unless(Client::where('platform_id', $platform->id)->whereIn('id', $ids)->count() === $ids->count(), 403, 'A selected escort belongs to another market.');
        }
        $kind = $scope === 'selected' ? 'selected_pass_grant' : 'active_pass_grant';
        $run = DB::transaction(function () use ($platform, $kind, $durationKey, $actor, $scope, $batchSize, $s, &$ids) {
            if ($scope !== 'selected') {
                // Serialise batch starts per market so two batches can never pick the same escorts.
                \App\Models\ContentMonetizationSetting::whereKey($s->id)->lockForUpdate()->first();
                $ids = $this->activeSubscriptionsRemaining($platform)->when($batchSize, fn ($q) => $q->limit($batchSize))->pluck('id');
                abort_if($ids->isEmpty(), 409, 'Every active subscription already has a free pass from an earlier batch.');
            }
            $rule = ['scope' => $scope, 'duration_key' => $durationKey, 'batch_size' => $scope === 'selected' ? null : $batchSize];
            $run = MonetizationAutomationRun::create(['public_id' => (string) Str::uuid(), 'platform_id' => $platform->id, 'kind' => $kind, 'status' => $ids->isEmpty() ? 'completed' : 'running', 'actor_id' => $actor, 'rule_json' => $rule, 'estimated_count' => $ids->count(), 'total_count' => $ids->count(), 'finished_at' => $ids->isEmpty() ? now() : null]);
            foreach ($ids->chunk(500) as $chunk) {
                $now = now();
                MonetizationAutomationItem::insert($chunk->map(fn ($id) => ['run_id' => $run->id, 'platform_id' => $platform->id, 'client_id' => $id, 'kind' => 'pass_grant', 'operation_key' => $run->public_id.':'.$id, 'rule_json' => json_encode($rule), 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now])->values()->all());
            }
            PremiumContentEvent::create(['platform_id' => $platform->id, 'actor_id' => $actor, 'kind' => 'automation_started', 'reason' => 'Started a free selling pass grant.', 'metadata_json' => ['run' => $run->public_id, 'recipients' => $ids->count(), 'rule' => $rule]]);

            return $run;
        });
        $run->items()->where('status', 'queued')->pluck('id')->each(fn ($id) => ProcessMonetizationAutomationItem::dispatch($id));

        return $run->fresh();
    }

    public function processItem(MonetizationAutomationItem $item): void
    {
        $client = $item->client_id ? Client::with('platform')->find($item->client_id) : null;
        $expiry = app(ExpiryAutomationService::class);
        if (! $client) {
            $expiry->finish($item, 'skipped', 'profile_missing');

            return;
        }
        $run = $item->run;
        $source = $run?->kind === 'selected_pass_grant' ? 'campaign_selected' : 'campaign_active_subscription';
        $result = $this->grant($client, $item->rule_json['duration_key'], $source, 'run:'.$item->operation_key, null, $run?->id, $run?->actor_id);
        $expiry->finish($item, $result['status'] === 'granted' ? 'succeeded' : 'skipped', $result['status'], ['pass_id' => $result['pass']?->id]);
    }
}
