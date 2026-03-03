<?php

namespace App\Services\PushCampaign;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class UploadBatchStatusService
{
    private const INDEX_KEY = 'push_upload:index';
    private const INDEX_LIMIT = 120;
    private const ACTIVE_STATUSES = ['queued', 'processing', 'extracting'];

    public function cacheKey(string $batchId): string
    {
        return 'push_upload:' . $batchId;
    }

    public function get(string $batchId): ?array
    {
        $payload = Cache::get($this->cacheKey($batchId));

        return is_array($payload) ? $payload : null;
    }

    public function put(string $batchId, array $payload, int $ttlHours = 12): array
    {
        $existing = $this->get($batchId) ?? [];
        $merged = array_merge($existing, $payload, [
            'batch_id' => $batchId,
        ]);

        Cache::put($this->cacheKey($batchId), $merged, now()->addHours($ttlHours));
        $this->touchIndex($batchId, max(24, $ttlHours * 2));

        return $merged;
    }

    /**
     * @return array{
     *     ahead_count:int,
     *     position:int|null,
     *     active_count:int,
     *     wait_seconds:int|null,
     *     health:array<string, mixed>|null,
     *     recent:array<int, array<string, mixed>>
     * }
     */
    public function queueOverviewForUser(int $userId, string $focusBatchId, int $limit = 8): array
    {
        $recent = $this->recentForUser($userId, max(25, $limit));
        $active = array_values(array_filter($recent, fn(array $batch): bool => in_array((string) ($batch['status'] ?? ''), self::ACTIVE_STATUSES, true)));

        usort($active, fn(array $a, array $b): int => strcmp((string) ($a['queued_at'] ?? ''), (string) ($b['queued_at'] ?? '')));

        $position = null;
        foreach ($active as $index => $batch) {
            if (($batch['batch_id'] ?? null) === $focusBatchId) {
                $position = $index + 1;
                break;
            }
        }

        $focus = $this->get($focusBatchId);
        $waitSeconds = null;

        if (is_array($focus) && ($focus['status'] ?? null) === 'queued' && !empty($focus['queued_at'])) {
            try {
                $waitSeconds = max(0, now()->diffInSeconds((string) $focus['queued_at']));
            } catch (\Throwable) {
                $waitSeconds = null;
            }
        }

        $health = $this->resolveQueueHealth($focus, $waitSeconds);

        return [
            'ahead_count' => $position ? max(0, $position - 1) : 0,
            'position' => $position,
            'active_count' => count($active),
            'wait_seconds' => $waitSeconds,
            'health' => $health,
            'recent' => array_slice($recent, 0, max(1, $limit)),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, int $limit = 20): array
    {
        return $this->recentForUser($userId, $limit);
    }

    /**
     * @return array{
     *     data:array<int, array<string, mixed>>,
     *     current_page:int,
     *     last_page:int,
     *     per_page:int,
     *     total:int,
     *     from:int|null,
     *     to:int|null
     * }
     */
    public function paginateForUser(int $userId, int $page = 1, int $perPage = 10): array
    {
        $safePerPage = max(1, $perPage);
        $all = $this->recentForUser($userId, self::INDEX_LIMIT);
        $total = count($all);
        $lastPage = max(1, (int) ceil($total / $safePerPage));
        $currentPage = max(1, min($page, $lastPage));
        $offset = ($currentPage - 1) * $safePerPage;
        $rows = array_slice($all, $offset, $safePerPage);

        return [
            'data' => $rows,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $safePerPage,
            'total' => $total,
            'from' => empty($rows) ? null : ($offset + 1),
            'to' => empty($rows) ? null : ($offset + count($rows)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function queueHealthSnapshot(): ?array
    {
        return $this->resolveQueueHealth(['status' => 'queued'], null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentForUser(int $userId, int $limit): array
    {
        $batchIds = Cache::get(self::INDEX_KEY, []);
        if (!is_array($batchIds)) {
            return [];
        }

        $rows = [];

        foreach ($batchIds as $batchId) {
            if (!is_string($batchId) || trim($batchId) === '') {
                continue;
            }

            $payload = $this->get($batchId);
            if (!is_array($payload)) {
                continue;
            }

            if ((int) ($payload['initiated_by'] ?? 0) !== $userId) {
                continue;
            }

            $rows[] = $this->normalizeQueueRow($payload);
        }

        usort($rows, fn(array $a, array $b): int => strcmp((string) ($b['queued_at'] ?? ''), (string) ($a['queued_at'] ?? '')));

        return array_slice($rows, 0, max(1, $limit));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeQueueRow(array $payload): array
    {
        return [
            'batch_id' => (string) ($payload['batch_id'] ?? ''),
            'source_filename' => (string) ($payload['source_filename'] ?? 'n/a'),
            'status' => (string) ($payload['status'] ?? 'queued'),
            'dry_run' => (bool) ($payload['dry_run'] ?? false),
            'queued_at' => $payload['queued_at'] ?? null,
            'started_at' => $payload['started_at'] ?? null,
            'updated_at' => $payload['updated_at'] ?? null,
            'year' => $payload['year'] ?? null,
            'sheets_parsed' => (int) ($payload['sheets_parsed'] ?? 0),
            'total_items' => (int) ($payload['total_items'] ?? 0),
            'profiles_processed' => (int) ($payload['profiles_processed'] ?? 0),
        ];
    }

    private function touchIndex(string $batchId, int $ttlHours): void
    {
        $existing = Cache::get(self::INDEX_KEY, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        $next = array_values(array_filter(
            $existing,
            fn($id): bool => is_string($id) && $id !== $batchId
        ));

        array_unshift($next, $batchId);

        Cache::put(self::INDEX_KEY, array_slice($next, 0, self::INDEX_LIMIT), now()->addHours($ttlHours));
    }

    /**
     * @param array<string, mixed>|null $focus
     * @return array<string, mixed>|null
     */
    private function resolveQueueHealth(?array $focus, ?int $waitSeconds): ?array
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        try {
            $pendingJobs = (int) DB::table('jobs')->where('queue', 'default')->count();
            $reservedJobs = (int) DB::table('jobs')
                ->where('queue', 'default')
                ->whereNotNull('reserved_at')
                ->count();
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($focus) || ($focus['status'] ?? null) !== 'queued') {
            return [
                'pending_jobs' => $pendingJobs,
                'reserved_jobs' => $reservedJobs,
                'worker_detected' => $reservedJobs > 0,
            ];
        }

        $workerDetected = $reservedJobs > 0;
        $workerLikelyOffline = !$workerDetected && $pendingJobs > 0 && ($waitSeconds ?? 0) >= 90;

        return [
            'pending_jobs' => $pendingJobs,
            'reserved_jobs' => $reservedJobs,
            'worker_detected' => $workerDetected,
            'worker_likely_offline' => $workerLikelyOffline,
            'message' => $workerLikelyOffline
                ? 'No active queue worker detected. Start `php artisan queue:work` (or Horizon) to process uploads.'
                : null,
        ];
    }
}
