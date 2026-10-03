<?php

namespace App\Services\Monetization;

use App\Jobs\ProcessMonetizationAutomationItem;
use App\Models\ContentMonetizationSetting;
use App\Models\MonetizationAutomationItem;
use App\Models\MonetizationAutomationRun;
use App\Models\Platform;
use App\Models\PremiumContentAsset;
use App\Models\PremiumContentEvent;
use App\Services\MonetizationSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Card previews for private videos: WordPress cuts a blurred, silent loop from the protected
 * original. New uploads get one at protect time; this backfills videos protected earlier and
 * re-encodes previews made at a different strength. One video per queue item, spaced out.
 */
class TeaserService
{
    public function __construct(private MonetizationSettingsService $settings) {}

    /** Private videos that can carry a card preview. Held, deleted and public items are excluded. */
    public function videos(Platform $platform): Builder
    {
        return PremiumContentAsset::where('platform_id', $platform->id)->where('media_type', 'video')->where('status', 'ready');
    }

    /** Videos without a preview at the market's current strength, not already waiting in a run. */
    public function remaining(Platform $platform, ?string $strength = null): Builder
    {
        $strength ??= $this->settings->teaserPolicy($this->settings->forPlatform($platform))['strength'];

        return $this->videos($platform)
            ->where(fn ($q) => $q->whereNull('teaser_url')->orWhereNull('teaser_strength')->orWhere('teaser_strength', '!=', $strength))
            ->whereNotIn('id', MonetizationAutomationItem::where('platform_id', $platform->id)->where('kind', 'teaser_generate')->whereIn('status', ['queued', 'running'])->whereNotNull('asset_id')->select('asset_id'))
            ->orderBy('id');
    }

    public function estimates(Platform $platform): array
    {
        $s = $this->settings->forPlatform($platform);
        $strength = $this->settings->teaserPolicy($s)['strength'];

        return ['teaser_videos' => $this->videos($platform)->count(), 'teaser_ready' => $this->videos($platform)->whereNotNull('teaser_url')->where('teaser_strength', $strength)->count(), 'teaser_remaining' => $this->remaining($platform, $strength)->count()];
    }

    public function startBackfill(Platform $platform, int $actor): MonetizationAutomationRun
    {
        $s = $this->settings->forPlatform($platform);
        $policy = $this->settings->teaserPolicy($s);
        abort_unless($policy['enabled'], 409, 'Switch on video previews and save before generating them.');
        $run = DB::transaction(function () use ($platform, $policy, $actor, $s) {
            // Serialise starts per market so two runs never pick the same videos.
            ContentMonetizationSetting::whereKey($s->id)->lockForUpdate()->first();
            $assets = $this->remaining($platform, $policy['strength'])->get(['id', 'client_id']);
            abort_if($assets->isEmpty(), 409, 'Every private video already has a preview at this strength.');
            $rule = ['strength' => $policy['strength']];
            $run = MonetizationAutomationRun::create(['public_id' => (string) Str::uuid(), 'platform_id' => $platform->id, 'kind' => 'teaser_backfill', 'status' => 'running', 'actor_id' => $actor, 'rule_json' => $rule, 'estimated_count' => $assets->count(), 'total_count' => $assets->count()]);
            foreach ($assets->chunk(500) as $chunk) {
                $now = now();
                MonetizationAutomationItem::insert($chunk->map(fn ($a) => ['run_id' => $run->id, 'platform_id' => $platform->id, 'client_id' => $a->client_id, 'asset_id' => $a->id, 'kind' => 'teaser_generate', 'operation_key' => $run->public_id.':'.$a->id, 'rule_json' => json_encode($rule), 'status' => 'queued', 'created_at' => $now, 'updated_at' => $now])->values()->all());
            }
            PremiumContentEvent::create(['platform_id' => $platform->id, 'actor_id' => $actor, 'kind' => 'automation_started', 'reason' => 'Started generating video previews.', 'metadata_json' => ['run' => $run->public_id, 'videos' => $assets->count(), 'rule' => $rule]]);

            return $run;
        });
        self::dispatchSpaced($run->items()->where('status', 'queued')->orderBy('id')->pluck('id'));

        return $run->fresh();
    }

    /** About one encode every three seconds, so a backfill never crowds the WordPress host. */
    public static function dispatchSpaced($ids): void
    {
        foreach (collect($ids)->values() as $index => $id) {
            ProcessMonetizationAutomationItem::dispatch($id)->delay(now()->addSeconds($index * 3));
        }
    }

    public function processItem(MonetizationAutomationItem $item): void
    {
        $expiry = app(ExpiryAutomationService::class);
        $asset = PremiumContentAsset::find($item->asset_id);
        if (! $asset || $asset->platform_id !== $item->platform_id || $asset->media_type !== 'video' || in_array($asset->status, ['deleted', 'public'], true)) {
            $expiry->finish($item, 'skipped', 'video_unavailable');

            return;
        }
        if ($asset->status === 'held') {
            $expiry->finish($item, 'skipped', 'held');

            return;
        }
        $strength = $item->rule_json['strength'] ?? 'shapes';
        if ($asset->teaser_url && $asset->teaser_strength === $strength) {
            $expiry->finish($item, 'skipped', 'preview_ready');

            return;
        }
        $s = ContentMonetizationSetting::with('platform')->where('platform_id', $asset->platform_id)->firstOrFail();
        $response = app(SyncService::class)->send($s, '/premium-content/teaser', ['public_id' => $asset->public_id, 'strength' => $strength], 100);
        if (($response['status'] ?? '') !== 'synced' || empty($response['response']['teaser_url'])) {
            $message = (string) ($response['message'] ?? '');
            $code = match (true) {
                str_contains($message, '(410)') => 'file_missing',
                str_contains($message, '(404)') => 'wordpress_outdated',
                str_contains($message, '(423)') => 'wordpress_busy',
                str_contains($message, '(503)') => 'video_processing_unavailable',
                str_contains($message, '(422)') => 'preview_failed',
                default => 'wordpress_unavailable',
            };
            $expiry->finish($item, $code === 'file_missing' ? 'skipped' : 'failed', $code, ['message' => $response['message'] ?? null]);

            return;
        }
        $asset->update(['teaser_url' => $response['response']['teaser_url'], 'teaser_strength' => $response['response']['teaser_strength'] ?? $strength, 'teaser_generated_at' => now()]);
        $expiry->finish($item, 'succeeded', 'generated');
    }
}
