<?php

namespace App\Services\Monetization;

use App\Jobs\ProcessMonetizationAutomationItem;
use App\Models\Client;
use App\Models\ContentMonetizationSetting;
use App\Models\MonetizationAutomationItem;
use App\Models\MonetizationAutomationRun;
use App\Models\Platform;
use App\Models\PremiumContentAsset;
use App\Models\PremiumContentEvent;
use App\Models\PremiumContentOffer;
use App\Services\MonetizationSettingsService;
use App\Support\ClientLifecycleState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Expired-profile media automation. WordPress owns selection and protected conversion;
 * CRM snapshots the rule, prices each converted item and publishes one live single offer
 * per item. Contact Unlock remains a separate purchase and is never touched here.
 */
class ExpiryAutomationService
{
    public function __construct(private MonetizationSettingsService $settings) {}

    /** Rule snapshot sent to WordPress and stored on every run/item. */
    public function rule(ContentMonetizationSetting $s): array
    {
        $policy = $this->settings->expiryPolicy($s);
        $marketMax = (int) data_get($s->offer_policy_json, 'max_video_seconds', 1800);

        return $policy + [
            'config_revision' => (int) $s->config_revision,
            'scale_bounds' => $this->settings->scaleBounds($policy, $marketMax),
            'duration_required' => $policy['video_duration']['mode'] !== 'any' || $policy['pricing']['mode'] === 'duration_scale',
        ];
    }

    /**
     * Price one converted item. Fixed mode assigns one amount; length-scaled mode maps the
     * duration linearly across the price range, clamps at the length bounds and rounds to
     * the whole-currency increment, never leaving the configured range.
     *
     * @return array{amount: int|null, reason: string|null, snapshot: array}
     */
    public function price(array $rule, string $mediaType, ?int $durationSeconds): array
    {
        $pricing = $rule['pricing'];
        $snapshot = ['mode' => $pricing['mode'], 'media_type' => $mediaType, 'duration_seconds' => $durationSeconds, 'round_increment' => $pricing['round_increment'], 'config_revision' => $rule['config_revision'] ?? null];
        if ($mediaType === 'photo') {
            return ['amount' => (int) $pricing['photo_amount'], 'reason' => null, 'snapshot' => $snapshot + ['mode' => 'photo_fixed', 'amount' => (int) $pricing['photo_amount']]];
        }
        if ($pricing['mode'] === 'fixed') {
            return ['amount' => (int) $pricing['fixed_amount'], 'reason' => null, 'snapshot' => $snapshot + ['amount' => (int) $pricing['fixed_amount']]];
        }
        if ($durationSeconds === null || $durationSeconds <= 0) {
            return ['amount' => null, 'reason' => 'duration_unavailable', 'snapshot' => $snapshot];
        }
        [$from, $to] = $rule['scale_bounds'];
        $min = (int) $pricing['min_amount'];
        $max = (int) $pricing['max_amount'];
        $ratio = max(0, min(1, ($durationSeconds - $from) / max(1, $to - $from)));
        $increment = max(1, (int) $pricing['round_increment']);
        $amount = (int) max($min, min($max, round(($min + $ratio * ($max - $min)) / $increment) * $increment));

        return ['amount' => $amount, 'reason' => null, 'snapshot' => $snapshot + ['min_amount' => $min, 'max_amount' => $max, 'min_seconds' => $from, 'max_seconds' => $to, 'ratio' => round($ratio, 4), 'amount' => $amount]];
    }

    /** Expired or archived profiles that remain publicly reachable in WordPress. */
    public function cohort(Platform $platform): Builder
    {
        return Client::query()->where('platform_id', $platform->id)->where('client_type', 'escort')->where('profile_status', 'publish')->whereNull('closed_at')
            ->whereIn('lifecycle_state', [ClientLifecycleState::EXPIRED, ClientLifecycleState::ARCHIVED])->whereNotNull('wp_post_id');
    }

    public function publiclyRestricted(Client $client): bool
    {
        return $client->profile_status === 'publish' && ! $client->closed_at && in_array($client->lifecycle_state, [ClientLifecycleState::EXPIRED, ClientLifecycleState::ARCHIVED], true);
    }

    public function assertRunnable(ContentMonetizationSetting $s): void
    {
        $this->settings->assertCommerce($s, 'checkout');
        abort_unless($this->settings->expiryPolicy($s)['media_types'], 422, 'Choose the content to monetize and save the automation first.');
    }

    public function startBackfill(Platform $platform, int $revision, int $actor): MonetizationAutomationRun
    {
        $s = $this->settings->forPlatform($platform);
        abort_unless((int) $s->config_revision === $revision, 409, 'Settings changed. Reload before applying the rule.');
        $this->assertRunnable($s);
        $rule = $this->rule($s);
        $run = DB::transaction(function () use ($platform, $rule, $actor) {
            $run = MonetizationAutomationRun::create(['public_id' => (string) Str::uuid(), 'platform_id' => $platform->id, 'kind' => 'expired_backfill', 'status' => 'queued', 'actor_id' => $actor, 'rule_json' => $rule]);
            $count = 0;
            $this->cohort($platform)->select('id')->orderBy('id')->chunkById(500, function ($clients) use ($run, $platform, $rule, &$count) {
                $now = now();
                MonetizationAutomationItem::insert($clients->map(fn ($c) => ['run_id' => $run->id, 'platform_id' => $platform->id, 'client_id' => $c->id, 'kind' => 'expiry_media', 'operation_key' => $run->public_id.':'.$c->id, 'rule_json' => json_encode($rule), 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now])->all());
                $count += $clients->count();
            });
            $run->update(['estimated_count' => $count, 'total_count' => $count, 'status' => $count ? 'running' : 'completed', 'finished_at' => $count ? null : now()]);
            PremiumContentEvent::create(['platform_id' => $platform->id, 'actor_id' => $actor, 'kind' => 'automation_started', 'reason' => 'Applied the expired-profile rule to existing expired profiles.', 'metadata_json' => ['run' => $run->public_id, 'profiles' => $count, 'rule' => $rule]]);

            return $run;
        });
        $run->items()->where('status', 'queued')->pluck('id')->each(fn ($id) => ProcessMonetizationAutomationItem::dispatch($id));

        return $run->fresh();
    }

    /** Called after a natural expiry commits. Never throws into the reconciler. */
    public function queueNaturalExpiry(Client $client): ?MonetizationAutomationItem
    {
        try {
            $platform = $client->platform ?? Platform::find($client->platform_id);
            if (! $platform) {
                return null;
            }
            $s = ContentMonetizationSetting::where('platform_id', $platform->id)->first();
            if (! $s) {
                return null;
            }
            $policy = $this->settings->expiryPolicy($s);
            if (! $policy['enabled'] || ! $policy['apply_to_future_expiries'] || ! $policy['media_types'] || ! $this->settings->runtime($s)['enabled']) {
                return null;
            }
            $key = 'expiry:'.$client->id.':'.optional($client->lifecycle_expired_at)->timestamp;
            $item = MonetizationAutomationItem::firstOrCreate(['operation_key' => $key], ['platform_id' => $client->platform_id, 'client_id' => $client->id, 'kind' => 'expiry_media', 'rule_json' => $this->rule($s), 'status' => 'queued']);
            if ($item->wasRecentlyCreated) {
                ProcessMonetizationAutomationItem::dispatch($item->id)->afterCommit();
            }

            return $item;
        } catch (Throwable $e) {
            Log::warning('Expiry media automation could not be queued; expiry stands.', ['client_id' => $client->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function process(MonetizationAutomationItem $item): void
    {
        $client = $item->client_id ? Client::with('platform')->find($item->client_id) : null;
        if (! $client) {
            $this->finish($item, 'skipped', 'profile_missing');

            return;
        }
        $client->refresh();
        if (! $this->publiclyRestricted($client)) {
            $this->finish($item, 'skipped', 'profile_not_restricted');

            return;
        }
        $s = $this->settings->forPlatform($client->platform);
        try {
            $this->settings->assertCommerce($s, 'checkout');
        } catch (Throwable $e) {
            $this->finish($item, 'failed', 'commerce_unavailable', ['message' => $e->getMessage()]);

            return;
        }
        $rule = $item->rule_json;
        // Items the escort took off sale stay off sale, including a later public copy.
        $excluded = PremiumContentAsset::where('platform_id', $client->platform_id)->where('client_id', $client->id)
            ->whereIn('id', DB::table('premium_content_offer_items')->whereIn('offer_id', PremiumContentOffer::where('client_id', $client->id)->where('origin', PremiumContentOffer::ORIGIN_EXPIRY)->whereNotNull('owner_opted_out_at')->select('id'))->select('asset_id'))
            ->pluck('wp_attachment_id')->map(fn ($id) => (int) $id)->values()->all();
        $response = app(SyncService::class)->send($s, '/premium-content/expiry-media-convert', [
            'wp_post_id' => (int) $client->wp_post_id, 'media_types' => $rule['media_types'], 'max_per_type' => $rule['max_per_type'],
            'video_duration' => $rule['video_duration'], 'duration_required' => (bool) $rule['duration_required'],
            'exclude_attachment_ids' => $excluded, 'operation_key' => $item->operation_key,
        ], 120);
        if (($response['status'] ?? '') !== 'synced') {
            $code = str_contains((string) ($response['message'] ?? ''), '(409)') ? 'profile_not_restricted' : (str_contains((string) ($response['message'] ?? ''), '(404)') ? 'wordpress_outdated' : 'wordpress_unavailable');
            $this->finish($item, $code === 'profile_not_restricted' ? 'skipped' : 'failed', $code, ['message' => $response['message'] ?? null]);

            return;
        }
        $body = $response['response'] ?? [];
        $created = [];
        $skipped = $body['skipped'] ?? [];
        foreach ($body['converted'] ?? [] as $converted) {
            $result = $this->publish($s, $client, $rule, $converted);
            if ($result['offer']) {
                $created[] = $result['offer']->public_id;
            } else {
                $skipped[] = ['wp_attachment_id' => $converted['wp_attachment_id'] ?? null, 'reason' => $result['reason']];
            }
        }
        if ($created) {
            PremiumContentEvent::create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'kind' => 'expiry_media_moved', 'reason' => count($created).' '.(count($created) === 1 ? 'item was' : 'items were').' moved to Private content when the listing expired.', 'metadata_json' => ['offers' => $created, 'operation_key' => $item->operation_key]]);
            app(SyncService::class)->profile($client);
        }
        $this->finish($item, $created ? 'succeeded' : 'skipped', $created ? 'converted' : ($skipped[0]['reason'] ?? 'no_eligible_media'), ['offers' => $created, 'skipped' => array_slice($skipped, 0, 20)]);
    }

    /** Register the protected asset and its priced live offer exactly once. */
    public function publish(ContentMonetizationSetting $s, Client $client, array $rule, array $converted): array
    {
        return DB::transaction(function () use ($s, $client, $rule, $converted) {
            $attachment = (int) ($converted['wp_attachment_id'] ?? 0);
            $key = 'expiry:'.$client->wp_post_id.':'.$attachment;
            $existing = PremiumContentOffer::where('platform_id', $client->platform_id)->where('origin_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return ['offer' => $existing->owner_opted_out_at ? null : $existing, 'reason' => 'owner_opted_out'];
            }
            $type = ($converted['media_type'] ?? '') === 'photo' ? 'photo' : 'video';
            $duration = isset($converted['duration_seconds']) ? (int) $converted['duration_seconds'] : null;
            $price = $this->price($rule, $type, $duration);
            if ($price['amount'] === null) {
                return ['offer' => null, 'reason' => $price['reason']];
            }
            $asset = PremiumContentAsset::firstOrCreate(['platform_id' => $client->platform_id, 'wp_attachment_id' => $attachment], [
                'public_id' => $converted['public_id'], 'client_id' => $client->id, 'wp_post_id' => $client->wp_post_id, 'media_type' => $type,
                'preview_url' => $converted['preview_url'], 'duration_seconds' => $duration,
                'teaser_url' => $type === 'video' ? ($converted['teaser_url'] ?? null) : null, 'teaser_strength' => $type === 'video' && ! empty($converted['teaser_url']) ? ($converted['teaser_strength'] ?? null) : null, 'teaser_generated_at' => $type === 'video' && ! empty($converted['teaser_url']) ? now() : null, 'content_fingerprint' => $converted['content_fingerprint'], 'origin' => PremiumContentOffer::ORIGIN_EXPIRY,
            ]);
            if ($asset->client_id !== $client->id) {
                return ['offer' => null, 'reason' => 'ownership_mismatch'];
            }
            $offer = PremiumContentOffer::create([
                'public_id' => (string) Str::uuid(), 'platform_id' => $client->platform_id, 'client_id' => $client->id, 'kind' => 'single', 'title' => null,
                'currency' => $s->currency, 'amount' => $price['amount'], 'status' => 'live', 'version' => 1, 'is_sandbox' => $s->rollout_mode === 'sandbox', 'published_at' => now(),
                'origin' => PremiumContentOffer::ORIGIN_EXPIRY, 'origin_key' => $key, 'pricing_snapshot_json' => $price['snapshot'],
            ]);
            $offer->assets()->sync([$asset->id => ['sort_order' => 0]]);

            return ['offer' => $offer, 'reason' => null];
        }, 3);
    }

    public function finish(MonetizationAutomationItem $item, string $status, string $code, array $result = []): void
    {
        DB::transaction(function () use ($item, $status, $code, $result) {
            $locked = MonetizationAutomationItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $previous = $locked->status;
            $locked->update(['status' => $status, 'result_code' => $code, 'result_json' => $result]);
            if ($locked->run_id) {
                self::tally($locked->run_id, $previous, $status);
            }
        });
    }

    /** Keep run counts exact across retries: a retried failure leaves the failed column. */
    public static function tally(int $runId, string $previous, string $next): void
    {
        $run = MonetizationAutomationRun::whereKey($runId)->lockForUpdate()->firstOrFail();
        $column = fn ($status) => ['succeeded' => 'succeeded_count', 'skipped' => 'skipped_count', 'failed' => 'failed_count'][$status] ?? null;
        if (($from = $column($previous)) && $run->{$from} > 0) {
            $run->{$from}--;
        }
        if ($to = $column($next)) {
            $run->{$to}++;
        }
        $done = $run->succeeded_count + $run->skipped_count + $run->failed_count;
        $run->status = $done >= $run->total_count ? ($run->failed_count ? 'completed_with_errors' : 'completed') : 'running';
        $run->finished_at = $done >= $run->total_count ? now() : null;
        $run->save();
    }

    public function presentRun(MonetizationAutomationRun $run): array
    {
        $reasons = $run->items()->whereIn('status', ['failed', 'skipped'])->selectRaw('status, result_code, COUNT(*) as total')->groupBy('status', 'result_code')->get()->map(fn ($r) => ['status' => $r->status, 'code' => $r->result_code, 'total' => (int) $r->total])->values();

        return ['public_id' => $run->public_id, 'kind' => $run->kind, 'status' => $run->status, 'total' => $run->total_count, 'succeeded' => $run->succeeded_count, 'skipped' => $run->skipped_count, 'failed' => $run->failed_count, 'reasons' => $reasons, 'rule' => $run->rule_json, 'created_at' => $run->created_at?->toIso8601String(), 'finished_at' => $run->finished_at?->toIso8601String()];
    }
}
