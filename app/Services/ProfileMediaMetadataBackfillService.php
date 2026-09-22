<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use App\Models\ProfileMediaMetadataBackfillRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class ProfileMediaMetadataBackfillService
{
    public const SLICE_SIZE = 5;

    /** @param array{scope:string,client_ids?:array<int, int>} $options */
    public function preview(Platform $platform, array $options): array
    {
        $candidateCount = (int) $this->candidates($platform, $options)
            ->toBase()
            ->getCountForPagination();
        $selectedCount = $options['scope'] === ProfileMediaMetadataBackfillRun::SCOPE_SELECTED
            ? count($options['client_ids'] ?? [])
            : null;

        return [
            'summary' => [
                'selected' => $selectedCount,
                'profiles' => $candidateCount,
                'skipped' => $selectedCount === null ? 0 : max(0, $selectedCount - $candidateCount),
            ],
            'sample' => $this->candidates($platform, $options)
                ->orderBy('id')
                ->limit(12)
                ->get(['id', 'name', 'city', 'wp_post_id'])
                ->map(fn (Client $client) => [
                    'id' => (int) $client->id,
                    'name' => (string) $client->name,
                    'city' => $client->city,
                    'wp_post_id' => (int) $client->wp_post_id,
                ])
                ->values(),
        ];
    }

    /** Process one bounded slice and return whether another should be queued. */
    public function processSlice(ProfileMediaMetadataBackfillRun $run): bool
    {
        $platform = Platform::query()->findOrFail((int) $run->platform_id);
        $options = [
            'scope' => $run->scope,
            'client_ids' => array_map('intval', $run->client_ids ?? []),
        ];

        if (! $run->started_at) {
            $run->forceFill([
                'status' => ProfileMediaMetadataBackfillRun::STATUS_RUNNING,
                'started_at' => now(),
                'candidate_count' => (int) $this->candidates($platform, $options)->toBase()->getCountForPagination(),
            ])->save();
        }

        $clients = $this->candidates($platform, $options)
            ->when($run->cursor_client_id, fn (Builder $query, int $cursor) => $query->where('id', '>', $cursor))
            ->orderBy('id')
            ->limit(self::SLICE_SIZE)
            ->get();

        if ($clients->isEmpty()) {
            $run->forceFill([
                'status' => ProfileMediaMetadataBackfillRun::STATUS_COMPLETED,
                'finished_at' => now(),
            ])->save();

            return false;
        }

        $wpSync = WpSyncService::forPlatform((int) $platform->id);
        $processed = (int) $run->processed_count;
        $attachmentsUpdated = (int) $run->attachments_updated_count;
        $skipped = (int) $run->skipped_count;
        $failed = (int) $run->failed_count;
        $notes = (string) ($run->notes ?? '');

        foreach ($clients as $client) {
            try {
                $result = $wpSync->synchronizeClientMediaMetadata((int) $client->wp_post_id);
                $updated = max(0, (int) ($result['attachments_updated'] ?? 0));
                $attachmentsUpdated += $updated;
                $skipped += $updated === 0 ? 1 : 0;
            } catch (\Throwable $exception) {
                $failed++;
                $notes = $this->appendFailureNote($notes, $client, $exception);
                Log::warning('Profile media metadata backfill failed for client.', [
                    'run_id' => (int) $run->id,
                    'client_id' => (int) $client->id,
                    'wp_post_id' => (int) $client->wp_post_id,
                    'error' => $exception->getMessage(),
                ]);
            }

            $processed++;
        }

        $lastClient = $clients->last();
        $run->forceFill([
            'processed_count' => $processed,
            'attachments_updated_count' => $attachmentsUpdated,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'cursor_client_id' => (int) $lastClient->id,
            'notes' => $notes !== '' ? $notes : null,
        ])->save();

        $hasMore = $clients->count() === self::SLICE_SIZE
            && $this->candidates($platform, $options)
                ->where('id', '>', (int) $lastClient->id)
                ->exists();

        if (! $hasMore) {
            $run->forceFill([
                'status' => ProfileMediaMetadataBackfillRun::STATUS_COMPLETED,
                'finished_at' => now(),
            ])->save();
        }

        return $hasMore;
    }

    /** @param array{scope:string,client_ids?:array<int, int>} $options */
    private function candidates(Platform $platform, array $options): Builder
    {
        $query = Client::query()
            ->where('platform_id', (int) $platform->id)
            ->whereNotNull('wp_post_id')
            ->where('wp_post_id', '>', 0);

        if ($options['scope'] === ProfileMediaMetadataBackfillRun::SCOPE_SELECTED) {
            $query->whereIn('id', $options['client_ids'] ?? []);
        }

        return $query;
    }

    private function appendFailureNote(string $notes, Client $client, \Throwable $exception): string
    {
        if (substr_count($notes, "\n") >= 4) {
            return $notes;
        }

        $message = preg_replace('/\s+/', ' ', trim($exception->getMessage())) ?: 'Unknown WordPress error.';
        $line = sprintf('%s (client #%d): %s', (string) $client->name, (int) $client->id, $message);

        return mb_substr(trim($notes."\n".$line), 0, 1800);
    }
}
