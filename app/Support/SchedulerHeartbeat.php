<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * The scheduler's own vital signs, written once per `schedule:run` tick.
 *
 * The old payload was a single `ran_at` timestamp, which answers "did a tick
 * run recently" and nothing else. That question returned `healthy` for the
 * whole of the 3 September outage, because the failure was never an absent
 * tick — it was too many concurrent ones. This file records enough to see that
 * shape: each tick opens an entry carrying its pid and closes it with a
 * duration, so two overlapping ticks leave two open entries with different
 * pids.
 *
 * Every operation is best-effort. A heartbeat that throws would take down the
 * scheduler it exists to watch.
 */
class SchedulerHeartbeat
{
    public const FILE = 'app/scheduler-heartbeat.json';

    /** Ticks retained in the rolling window. */
    private const MAX_TICKS = 20;

    /** A tick still open after this long is treated as abandoned, not running. */
    private const OPEN_TICK_MAX_SECONDS = 900;

    public static function path(): string
    {
        return storage_path(self::FILE);
    }

    /**
     * Record the start of a tick. Returns the tick id used to close it.
     */
    public static function open(int $dueCount): ?string
    {
        $tickId = bin2hex(random_bytes(6));

        self::mutate(function (array $payload) use ($tickId, $dueCount): array {
            $payload['ticks'][] = [
                'tick_id' => $tickId,
                'pid' => getmypid() ?: null,
                'started_at' => now()->toIso8601String(),
                'ran_at' => now()->toIso8601String(),
                'due_count' => $dueCount,
                'duration_ms' => null,
            ];

            $payload['ran_at'] = now()->toIso8601String();
            $payload['pid'] = getmypid() ?: null;
            $payload['due_count'] = $dueCount;

            return $payload;
        });

        return $tickId;
    }

    /**
     * Close the tick opened above, stamping how long the tick actually took.
     */
    public static function close(?string $tickId, float $startedAtMicrotime): void
    {
        if ($tickId === null) {
            return;
        }

        $durationMs = (int) round((microtime(true) - $startedAtMicrotime) * 1000);

        self::mutate(function (array $payload) use ($tickId, $durationMs): array {
            foreach ($payload['ticks'] as $index => $tick) {
                if (($tick['tick_id'] ?? null) === $tickId) {
                    $payload['ticks'][$index]['duration_ms'] = $durationMs;
                    $payload['ticks'][$index]['ended_at'] = now()->toIso8601String();
                }
            }

            $payload['duration_ms'] = $durationMs;

            return $payload;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function read(): array
    {
        $path = self::path();

        if (! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) @file_get_contents($path), true);

        return is_array($payload) ? self::normalize($payload) : [];
    }

    /**
     * Ticks that opened and never closed, excluding ones old enough that the
     * process almost certainly died without closing its entry.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<int, array<string, mixed>>
     */
    public static function openTicks(?array $payload = null): array
    {
        $payload ??= self::read();
        $cutoff = time() - self::OPEN_TICK_MAX_SECONDS;

        $open = [];

        foreach ($payload['ticks'] ?? [] as $tick) {
            if ($tick['duration_ms'] !== null) {
                continue;
            }

            $startedAt = strtotime((string) ($tick['started_at'] ?? '')) ?: 0;

            if ($startedAt < $cutoff) {
                continue;
            }

            // Concurrency is measured in PROCESSES. One process cannot run two
            // ticks at once, so repeated entries from the same pid collapse to
            // one — otherwise a process that opened several entries would look
            // like the pile-up rather than a bookkeeping artefact.
            $pid = $tick['pid'];
            $open[$pid === null ? 'unknown:'.($tick['tick_id'] ?? count($open)) : 'pid:'.$pid] = $tick;
        }

        return array_values($open);
    }

    /**
     * The most recently completed tick, which is the one whose duration means
     * anything. An in-flight tick has no duration yet.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>|null
     */
    public static function lastCompletedTick(?array $payload = null): ?array
    {
        $payload ??= self::read();

        $completed = array_filter(
            $payload['ticks'] ?? [],
            fn (array $tick): bool => $tick['duration_ms'] !== null
        );

        return $completed === [] ? null : end($completed);
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $mutator
     */
    private static function mutate(callable $mutator): void
    {
        $path = self::path();

        try {
            $directory = dirname($path);
            if (! is_dir($directory)) {
                @mkdir($directory, 0775, true);
            }

            $handle = @fopen($path, 'c+');
            if ($handle === false) {
                return;
            }

            try {
                // Two ticks writing at once is exactly the condition this file
                // exists to record, so the write itself has to tolerate it.
                @flock($handle, LOCK_EX);

                $raw = (string) stream_get_contents($handle);
                $payload = json_decode($raw, true);
                $payload = self::normalize(is_array($payload) ? $payload : []);

                $payload = $mutator($payload);
                $payload['ticks'] = array_slice(array_values($payload['ticks']), -self::MAX_TICKS);

                $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, (string) $encoded);
                fflush($handle);
            } finally {
                @flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable $exception) {
            Log::warning('Unable to update scheduler heartbeat file.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalize(array $payload): array
    {
        $ticks = [];

        foreach ($payload['ticks'] ?? [] as $tick) {
            if (! is_array($tick)) {
                continue;
            }

            $ticks[] = [
                'tick_id' => isset($tick['tick_id']) ? (string) $tick['tick_id'] : null,
                'pid' => isset($tick['pid']) ? (int) $tick['pid'] : null,
                'started_at' => $tick['started_at'] ?? null,
                'ran_at' => $tick['ran_at'] ?? ($tick['started_at'] ?? null),
                'ended_at' => $tick['ended_at'] ?? null,
                'due_count' => isset($tick['due_count']) ? (int) $tick['due_count'] : null,
                'duration_ms' => isset($tick['duration_ms']) && $tick['duration_ms'] !== null
                    ? (int) $tick['duration_ms']
                    : null,
            ];
        }

        $payload['ticks'] = $ticks;

        return $payload;
    }
}
