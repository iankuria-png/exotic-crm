<?php

namespace App\Services;

use App\Models\Client;
use App\Models\LifecycleArchiveRecoveryRun;
use App\Models\Platform;
use App\Support\ClientLifecycleState;
use App\Support\LifecyclePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class LifecycleArchiveRecoveryService
{
    private const PREVIEW_SAMPLE_SIZE = 12;

    private const WORDPRESS_WRITE_THROTTLE_MICROSECONDS = 250000;

    public function __construct(
        private readonly ClientLifecycleService $lifecycle,
    ) {}

    /** @param array{scope:string,mode:string,client_ids?:array<int, int>} $options */
    public function preview(Platform $platform, array $options): array
    {
        $archiveAfterDays = LifecyclePolicy::archiveAfterDays();
        $query = $this->candidates($platform, $options, $archiveAfterDays);
        $candidateCount = (int) (clone $query)->toBase()->getCountForPagination();
        $selectedCount = $options['scope'] === LifecycleArchiveRecoveryRun::SCOPE_SELECTED
            ? count($options['client_ids'] ?? [])
            : null;
        $skipped = $selectedCount === null ? 0 : max(0, $selectedCount - $candidateCount);
        $deferredUntil = $options['mode'] === LifecycleArchiveRecoveryRun::MODE_OVERRIDE
            ? now()->addDays($archiveAfterDays)
            : null;

        return [
            'archive_after_days' => $archiveAfterDays,
            'archive_deferred_until' => optional($deferredUntil)->toIso8601String(),
            'summary' => [
                'selected' => $selectedCount,
                'candidate' => $candidateCount,
                'will_restore' => $candidateCount,
                'skipped' => $skipped,
            ],
            'sample' => (clone $query)
                ->orderBy('lifecycle_expired_at')
                ->limit(self::PREVIEW_SAMPLE_SIZE)
                ->get(['id', 'name', 'city', 'lifecycle_expired_at'])
                ->map(fn (Client $client) => [
                    'id' => (int) $client->id,
                    'name' => (string) $client->name,
                    'city' => $client->city,
                    'expired_at' => optional($client->lifecycle_expired_at)->toDateString(),
                    'outcome' => $options['mode'] === LifecycleArchiveRecoveryRun::MODE_OVERRIDE
                        ? 'restore_with_defer'
                        : 'restore',
                ])
                ->values(),
        ];
    }

    public function execute(LifecycleArchiveRecoveryRun $run): void
    {
        $platform = Platform::query()->findOrFail((int) $run->platform_id);

        if (! $platform->lifecycleEnabled()) {
            $this->fail($run, 'The profile lifecycle policy is not enabled for this market.');

            return;
        }

        $startedAt = now();
        $deferredUntil = $run->isOverride()
            ? $startedAt->copy()->addDays((int) $run->archive_after_days)
            : null;
        $run->forceFill([
            'status' => LifecycleArchiveRecoveryRun::STATUS_RUNNING,
            'started_at' => $startedAt,
            'archive_deferred_until' => $deferredUntil,
        ])->save();

        $options = [
            'scope' => $run->scope,
            'mode' => $run->mode,
            'client_ids' => array_map('intval', $run->client_ids ?? []),
        ];
        $candidateCount = (int) $this->candidates($platform, $options, (int) $run->archive_after_days)
            ->toBase()
            ->getCountForPagination();
        $run->forceFill(['candidate_count' => $candidateCount])->save();

        $restored = 0;
        $skipped = 0;
        $failed = 0;

        $this->candidates($platform, $options, (int) $run->archive_after_days)
            ->with('platform')
            ->orderBy('id')
            ->chunkById(100, function ($clients) use ($run, $platform, $deferredUntil, &$restored, &$skipped, &$failed): bool {
                foreach ($clients as $client) {
                    try {
                        $fresh = $client->fresh(['platform']);
                        if (! $fresh || ! $this->isCandidate($fresh, $platform, $run)) {
                            $skipped++;

                            continue;
                        }

                        $this->lifecycle->unarchive(
                            $fresh,
                            $run->requested_by ? (int) $run->requested_by : null,
                            $deferredUntil,
                            $run->isOverride() ? 'archive_recovery_override' : 'archive_recovery_policy',
                            (int) $run->id,
                        );
                        $restored++;
                    } catch (\Throwable $exception) {
                        $failed++;
                        Log::error('Lifecycle archive recovery failed for client', [
                            'run_id' => (int) $run->id,
                            'client_id' => (int) $client->id,
                            'error' => $exception->getMessage(),
                        ]);
                    } finally {
                        // Lifecycle changes are mirrored to WordPress. Pace a recovery run so a
                        // large historical cohort remains safe for the market site and its API.
                        usleep(self::WORDPRESS_WRITE_THROTTLE_MICROSECONDS);
                    }
                }

                $run->forceFill([
                    'restored_count' => $restored,
                    'skipped_count' => $skipped,
                    'failed_count' => $failed,
                ])->save();

                return true;
            });

        $run->forceFill([
            'status' => LifecycleArchiveRecoveryRun::STATUS_COMPLETED,
            'restored_count' => $restored,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'finished_at' => now(),
        ])->save();
    }

    /** @param array{scope:string,mode:string,client_ids?:array<int, int>} $options */
    private function candidates(Platform $platform, array $options, int $archiveAfterDays): Builder
    {
        $query = Client::query()
            ->where('platform_id', (int) $platform->id)
            ->where('lifecycle_state', ClientLifecycleState::ARCHIVED)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0);

        if ($options['scope'] === LifecycleArchiveRecoveryRun::SCOPE_SELECTED) {
            $query->whereIn('id', $options['client_ids'] ?? []);
        }

        if ($options['mode'] === LifecycleArchiveRecoveryRun::MODE_POLICY) {
            $query->whereNotNull('lifecycle_expired_at')
                ->where('lifecycle_expired_at', '>', now()->subDays($archiveAfterDays));
        }

        return $query;
    }

    private function isCandidate(Client $client, Platform $platform, LifecycleArchiveRecoveryRun $run): bool
    {
        if (! $platform->lifecycleEnabled() || $client->lifecycle_state !== ClientLifecycleState::ARCHIVED) {
            return false;
        }

        if ($run->scope === LifecycleArchiveRecoveryRun::SCOPE_SELECTED
            && ! in_array((int) $client->id, array_map('intval', $run->client_ids ?? []), true)) {
            return false;
        }

        return $run->isOverride()
            || ($client->lifecycle_expired_at !== null
                && $client->lifecycle_expired_at->greaterThan(now()->subDays((int) $run->archive_after_days)));
    }

    private function fail(LifecycleArchiveRecoveryRun $run, string $message): void
    {
        $run->forceFill([
            'status' => LifecycleArchiveRecoveryRun::STATUS_FAILED,
            'notes' => $message,
            'finished_at' => now(),
        ])->save();
    }
}
