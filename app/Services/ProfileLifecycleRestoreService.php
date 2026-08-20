<?php

namespace App\Services;

use App\Models\Client;
use App\Models\LifecycleRestoreRun;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Support\ClientLifecycleState;
use App\Support\LifecycleRestoreEligibility;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * SEO Recovery: bulk-republish profiles the legacy expiry sweep took offline.
 *
 * Before the profile lifecycle existed, a lapsed subscription set the WordPress
 * post to `private`, which deleted years of indexable content and internal links
 * from city pages. This service walks that backlog and puts each profile back as
 * Expired or Archived — published and indexed, contacts hidden — as if the
 * lifecycle had always been in place.
 */
class ProfileLifecycleRestoreService
{
    /** Pause between WordPress writes so a large batch doesn't hammer the market site. */
    private const WP_WRITE_THROTTLE_MICROSECONDS = 250_000;

    /** Rows returned by preview(). Enough to eyeball a batch, cheap enough for a web request. */
    private const PREVIEW_SAMPLE_SIZE = 25;

    public function __construct(
        private readonly ProfileBioScrubService $bioScrubber,
        private readonly ActiveSubscriptionProfileRepairService $activeSubscriptionRepair,
    ) {}

    /**
     * Eligible profiles for a run's configuration.
     */
    public function candidates(LifecycleRestoreRun $run): Builder
    {
        return LifecycleRestoreEligibility::fromRun($run->filters)
            ->query((int) $run->platform_id)
            ->with(['deals', 'payments'])
            ->orderBy('id');
    }

    /**
     * Count + a sample of what a run would do. Writes nothing — this is what
     * powers the mandatory dry run before the Run button unlocks.
     */
    public function preview(LifecycleRestoreRun $run): array
    {
        $query = $this->candidates($run);

        $total = (int) (clone $query)->toBase()->getCountForPagination();
        $capped = min($total, max((int) $run->batch_limit, 0));

        $sample = (clone $query)
            ->limit(self::PREVIEW_SAMPLE_SIZE)
            ->get()
            ->map(function (Client $client) use ($run) {
                $expiredAt = $this->resolveHistoricalExpiry($client);
                $state = $this->resolveLandingState($run, $expiredAt);

                return [
                    'id' => (int) $client->id,
                    'name' => $client->name,
                    'city' => $client->city,
                    'wp_post_id' => (int) $client->wp_post_id,
                    'seo_score' => $client->seo_score !== null ? (int) $client->seo_score : null,
                    'has_image' => (bool) ($client->main_image_url ?: $client->display_image_url),
                    'resolved_expiry' => $expiredAt->toDateString(),
                    'expiry_source' => $this->resolveExpirySource($client),
                    'landing_state' => $state,
                ];
            })
            ->all();

        $landingSplit = ['expired' => 0, 'archived' => 0];
        foreach ($sample as $row) {
            $landingSplit[$row['landing_state']] = ($landingSplit[$row['landing_state']] ?? 0) + 1;
        }

        return [
            'candidate_count' => $total,
            'will_process' => $capped,
            'sample' => $sample,
            'sample_landing_split' => $landingSplit,
            'filters' => LifecycleRestoreEligibility::fromRun($run->filters)->toArray(),
        ];
    }

    /**
     * Republish a batch. A dry run records the candidate count and stops.
     *
     * One bad profile must never kill the batch, so every profile is wrapped
     * and failures are tallied rather than thrown.
     */
    public function execute(LifecycleRestoreRun $run): array
    {
        $platform = Platform::query()->findOrFail($run->platform_id);

        if (! $platform->lifecycleEnabled()) {
            $run->forceFill([
                'status' => LifecycleRestoreRun::STATUS_FAILED,
                'notes' => 'Market does not have the profile lifecycle enabled.',
                'finished_at' => now(),
            ])->save();

            return ['restored' => 0, 'skipped' => 0, 'failed' => 0, 'candidates' => 0];
        }

        $run->forceFill([
            'status' => LifecycleRestoreRun::STATUS_RUNNING,
            'started_at' => now(),
        ])->save();

        $candidateCount = (int) $this->candidates($run)->toBase()->getCountForPagination();

        if (! $run->isLive()) {
            $run->forceFill([
                'status' => LifecycleRestoreRun::STATUS_COMPLETED,
                'candidate_count' => $candidateCount,
                'finished_at' => now(),
                'notes' => 'Dry run — no profiles were modified.',
            ])->save();

            return ['restored' => 0, 'skipped' => 0, 'failed' => 0, 'candidates' => $candidateCount];
        }

        $wp = WpSyncService::forPlatform((int) $platform->id);
        $sync = new ClientSyncService($platform);

        $limit = max((int) $run->batch_limit, 0);
        $restored = 0;
        $skipped = 0;
        $failed = 0;

        // chunkById, never ->get(): an unbounded fetch over a market's whole
        // offline backlog has OOM-ed production at 512M before.
        $this->candidates($run)->chunkById(100, function ($clients) use (
            $run, $wp, $sync, $limit, &$restored, &$skipped, &$failed
        ) {
            foreach ($clients as $client) {
                if ($restored + $failed >= $limit) {
                    return false; // batch cap reached — stop chunking
                }

                try {
                    if ($this->activeSubscriptionRepair->hasFutureActiveDeal($client)) {
                        $this->activeSubscriptionRepair->repairClient(
                            $client,
                            null,
                            false,
                            'profile_restore_active_subscription_skip'
                        );
                        $skipped++;

                        continue;
                    }

                    $expiredAt = $this->resolveHistoricalExpiry($client);
                    $state = $this->resolveLandingState($run, $expiredAt);

                    // setLifecycleState() republishes (post_status = publish) and
                    // stamps crm_lifecycle_state, so it is the whole WP-side write.
                    $wp->setLifecycleState((int) $client->wp_post_id, $state);

                    // Re-mirror WordPress, then stamp — the stamp is authoritative
                    // and must not be clobbered by the sync.
                    try {
                        $sync->syncOne((int) $client->wp_post_id);
                    } catch (\Throwable $syncError) {
                        Log::warning('SEO Recovery: re-sync after republish failed', [
                            'client_id' => $client->id,
                            'error' => $syncError->getMessage(),
                        ]);
                    }

                    $fresh = $client->fresh() ?? $client;
                    $fresh->forceFill([
                        'profile_status' => 'publish',
                        'lifecycle_state' => $state,
                        'lifecycle_expired_at' => $expiredAt,
                        'lifecycle_archived_at' => $state === ClientLifecycleState::ARCHIVED ? now() : null,
                        'lifecycle_restored_at' => now(),
                        'lifecycle_restore_run_id' => $run->id,
                    ])->save();

                    // A republished bio may still carry phone/WhatsApp/email —
                    // restricted profiles must not generate leads.
                    $this->bioScrubber->scrubQuietly($fresh, $run->requested_by);

                    TimelineEvent::create([
                        'platform_id' => (int) $client->platform_id,
                        'entity_type' => 'client',
                        'entity_id' => (int) $client->id,
                        'event_type' => 'profile_restored_from_offline',
                        'actor_id' => $run->requested_by,
                        'content' => [
                            'run_id' => (int) $run->id,
                            'landing_state' => $state,
                            'resolved_expiry' => $expiredAt->toDateTimeString(),
                            'expiry_source' => $this->resolveExpirySource($client),
                        ],
                        'created_at' => now(),
                    ]);

                    $restored++;
                } catch (\Throwable $exception) {
                    $failed++;
                    Log::error('SEO Recovery: profile restore failed', [
                        'run_id' => $run->id,
                        'client_id' => $client->id,
                        'wp_post_id' => $client->wp_post_id,
                        'error' => $exception->getMessage(),
                    ]);
                }

                usleep(self::WP_WRITE_THROTTLE_MICROSECONDS);
            }

            return true;
        });

        $run->forceFill([
            'status' => LifecycleRestoreRun::STATUS_COMPLETED,
            'candidate_count' => $candidateCount,
            'restored_count' => $restored,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'finished_at' => now(),
        ])->save();

        return [
            'restored' => $restored,
            'skipped' => $skipped,
            'failed' => $failed,
            'candidates' => $candidateCount,
        ];
    }

    /**
     * Put a batch back offline. Must exist before any live run — a bulk
     * republish with no undo is not a shippable operation.
     *
     * Deliberately does NOT clear `bio_original_html`: the scrubbed bio stays
     * in WordPress while the post is private, and the stored original is the
     * only copy of the advertiser's real text for a future renewal.
     */
    public function revert(LifecycleRestoreRun $run): array
    {
        $platform = Platform::query()->findOrFail((int) $run->platform_id);
        $wp = WpSyncService::forPlatform((int) $run->platform_id);
        $sync = new ClientSyncService($platform);

        $reverted = 0;
        $skipped = 0;
        $failed = 0;

        Client::query()
            ->where('lifecycle_restore_run_id', $run->id)
            ->orderBy('id')
            ->chunkById(100, function ($clients) use ($wp, $sync, &$reverted, &$skipped, &$failed) {
                foreach ($clients as $client) {
                    try {
                        if ($this->activeSubscriptionRepair->hasFutureActiveDeal($client)) {
                            $this->activeSubscriptionRepair->repairClient(
                                $client,
                                null,
                                false,
                                'profile_restore_revert_active_subscription_skip'
                            );
                            $skipped++;

                            continue;
                        }

                        $this->deactivateAndVerify(
                            $client,
                            $wp,
                            $sync,
                            null,
                            'profile_restore_reverted',
                            [
                                'run_id' => (int) $client->lifecycle_restore_run_id,
                                'scope' => 'run_revert',
                            ]
                        );
                        $reverted++;
                    } catch (\Throwable $exception) {
                        $failed++;
                        Log::error('SEO Recovery: revert failed', [
                            'client_id' => $client->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }

                    usleep(self::WP_WRITE_THROTTLE_MICROSECONDS);
                }

                return true;
            });

        $run->forceFill([
            'status' => LifecycleRestoreRun::STATUS_REVERTED,
            'notes' => trim((string) $run->notes.sprintf(' Reverted %d profile(s), skipped %d active subscription profile(s) on %s.', $reverted, $skipped, now()->toDateTimeString())),
        ])->save();

        return ['reverted' => $reverted, 'skipped' => $skipped, 'failed' => $failed];
    }

    /**
     * Emergency rollback for a market when the SEO lifecycle is disabled.
     *
     * Only restricted, published lifecycle profiles are targeted. A paid profile
     * that has already returned to Active is deliberately left alone.
     */
    public function rollbackMarket(int $platformId, ?int $actorId = null, string $reason = 'lifecycle_disabled'): array
    {
        $platform = Platform::query()->findOrFail($platformId);
        $wp = WpSyncService::forPlatform($platformId);
        $sync = new ClientSyncService($platform);

        $query = $this->rollbackCandidates($platformId);
        $candidateCount = (int) (clone $query)->toBase()->getCountForPagination();
        $reverted = 0;
        $failed = 0;

        $query->chunkById(100, function ($clients) use ($wp, $sync, $actorId, $reason, &$reverted, &$failed) {
            foreach ($clients as $client) {
                try {
                    $this->deactivateAndVerify(
                        $client,
                        $wp,
                        $sync,
                        $actorId,
                        'profile_lifecycle_rollback',
                        [
                            'reason' => $reason,
                            'scope' => 'market_lifecycle_disable',
                            'previous_lifecycle_state' => $client->lifecycle_state,
                            'run_id' => $client->lifecycle_restore_run_id ? (int) $client->lifecycle_restore_run_id : null,
                            'restored_at' => optional($client->lifecycle_restored_at)->toDateTimeString(),
                        ]
                    );
                    $reverted++;
                } catch (\Throwable $exception) {
                    $failed++;
                    Log::error('SEO Recovery: market rollback failed', [
                        'client_id' => $client->id,
                        'platform_id' => $client->platform_id,
                        'wp_post_id' => $client->wp_post_id,
                        'reason' => $reason,
                        'error' => $exception->getMessage(),
                    ]);
                }

                usleep(self::WP_WRITE_THROTTLE_MICROSECONDS);
            }

            return true;
        });

        return [
            'candidates' => $candidateCount,
            'reverted' => $reverted,
            'failed' => $failed,
        ];
    }

    private function rollbackCandidates(int $platformId): Builder
    {
        return Client::query()
            ->where('platform_id', $platformId)
            ->where('profile_status', 'publish')
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0)
            ->whereIn('lifecycle_state', [
                ClientLifecycleState::EXPIRED,
                ClientLifecycleState::ARCHIVED,
            ])
            ->whereDoesntHave('deals', function (Builder $deal): void {
                $deal->where('status', 'active')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '>', now());
            })
            ->orderBy('id');
    }

    private function deactivateAndVerify(
        Client $client,
        WpSyncService $wp,
        ClientSyncService $sync,
        ?int $actorId,
        string $eventType,
        array $eventContent,
    ): Client {
        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            throw new \InvalidArgumentException('Client is not linked to a WordPress profile.');
        }

        $wp->deactivateClient($wpPostId);
        $synced = $sync->syncOne($wpPostId);

        if ((string) $synced->profile_status !== 'private') {
            throw new \RuntimeException(sprintf(
                'WordPress still reports post_status=%s after deactivate.',
                (string) $synced->profile_status
            ));
        }

        Client::withoutRetentionRefresh(function () use ($synced): void {
            $synced->forceFill([
                'profile_status' => 'private',
                'lifecycle_state' => ClientLifecycleState::ACTIVE,
                'lifecycle_expired_at' => null,
                'lifecycle_archived_at' => null,
                'lifecycle_restored_at' => null,
                'lifecycle_restore_run_id' => null,
            ])->save();
        });

        TimelineEvent::create([
            'platform_id' => (int) $synced->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $synced->id,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'content' => $eventContent,
            'created_at' => now(),
        ]);

        return $synced->fresh(['platform']) ?? $synced;
    }

    /**
     * The dating rule.
     *
     * The legacy sweep deleted `escort_expire`, so the true expiry has to be
     * recovered from CRM records. First hit wins; `updated_at` is the
     * last-resort fallback and is always present.
     */
    public function resolveHistoricalExpiry(Client $client): CarbonInterface
    {
        $dealExpiry = $client->deals
            ->pluck('expires_at')
            ->filter()
            ->max();

        if ($dealExpiry) {
            return Carbon::parse($dealExpiry);
        }

        $paymentEnd = $client->payments
            ->pluck('end_date')
            ->filter()
            ->max();

        if ($paymentEnd) {
            return Carbon::parse($paymentEnd);
        }

        if ($client->churned_at) {
            return Carbon::parse($client->churned_at);
        }

        return Carbon::parse($client->updated_at ?? now());
    }

    /** Which source the dating rule used — surfaced in preview so a batch is explainable. */
    public function resolveExpirySource(Client $client): string
    {
        if ($client->deals->pluck('expires_at')->filter()->isNotEmpty()) {
            return 'deal';
        }

        if ($client->payments->pluck('end_date')->filter()->isNotEmpty()) {
            return 'payment';
        }

        if ($client->churned_at) {
            return 'churned_at';
        }

        return 'updated_at';
    }

    /**
     * Where a restored profile lands.
     *
     * Backfilling as if the lifecycle had always existed: a recently-lapsed
     * profile returns to city listings as Expired, while a two-year-old one
     * goes straight to Archived so it keeps its indexable URL without flooding
     * live listings. An explicit `target_state` on the run overrides this.
     */
    public function resolveLandingState(LifecycleRestoreRun $run, CarbonInterface $expiredAt): string
    {
        if (in_array($run->target_state, [ClientLifecycleState::EXPIRED, ClientLifecycleState::ARCHIVED], true)) {
            return $run->target_state;
        }

        $archiveAfterDays = (int) config('crm.lifecycle.archive_after_days', 90);

        return $expiredAt->copy()->addDays($archiveAfterDays)->isPast()
            ? ClientLifecycleState::ARCHIVED
            : ClientLifecycleState::EXPIRED;
    }
}
