<?php

namespace App\Services;

use App\Models\ErrorLogGroup;
use App\Models\ErrorLogOccurrence;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Throwable;

class ErrorLogRecorder
{
    public const MAX_OCCURRENCES_PER_GROUP = 20;

    private bool $recording = false;

    public function record(
        string $level,
        ?Throwable $exception,
        string $message = '',
        array $context = [],
        string $source = 'exception'
    ): void {
        if ($this->recording) {
            return;
        }

        $this->recording = true;

        try {
            $exceptionClass = $exception ? get_class($exception) : null;
            $resolvedMessage = $exception ? $exception->getMessage() : $message;
            $file = $exception ? $this->normalizeFilePath($exception->getFile()) : null;
            $line = $exception ? $exception->getLine() : null;
            $trace = $exception ? $exception->getTraceAsString() : null;

            $signature = $this->buildSignature($level, $exceptionClass, $file, $line, $resolvedMessage, $source);

            DB::transaction(function () use (
                $signature,
                $level,
                $exceptionClass,
                $resolvedMessage,
                $file,
                $line,
                $source,
                $trace,
                $context
            ) {
                $group = ErrorLogGroup::firstOrNew(['signature' => $signature]);

                if (!$group->exists) {
                    $group->level = $this->normalizeLevel($level);
                    $group->exception_class = $exceptionClass;
                    $group->message = $resolvedMessage;
                    $group->file = $file;
                    $group->line = $line;
                    $group->source = $source;
                    $group->occurrence_count = 0;
                    $group->first_seen_at = now();
                }

                $group->occurrence_count = ($group->occurrence_count ?? 0) + 1;
                $group->last_seen_at = now();

                if ($group->resolved_at && $group->last_seen_at->gt($group->resolved_at)) {
                    $group->resolved_at = null;
                    $group->resolved_by = null;
                }

                $group->save();

                $occurrence = ErrorLogOccurrence::create([
                    'group_id' => $group->id,
                    'occurred_at' => now(),
                    'trace' => $trace,
                    'context' => $this->sanitizeContext($context),
                    'url' => $this->safeUrl(),
                    'method' => $this->safeMethod(),
                    'user_id' => $this->safeUserId(),
                    'ip' => $this->safeIp(),
                    'platform_id' => null,
                ]);

                $group->last_occurrence_id = $occurrence->id;
                $group->saveQuietly();

                $this->pruneOccurrences($group->id);
            });
        } catch (Throwable $e) {
            // Swallow — the recorder must never throw or it will recurse through the exception handler.
        } finally {
            $this->recording = false;
        }
    }

    public function recordException(Throwable $exception, array $context = [], string $source = 'exception'): void
    {
        $this->record('error', $exception, '', $context, $source);
    }

    private function buildSignature(
        string $level,
        ?string $exceptionClass,
        ?string $file,
        ?int $line,
        string $message,
        string $source
    ): string {
        $normalizedMessage = $this->normalizeMessage($message);

        return sha1(implode('|', [
            $this->normalizeLevel($level),
            $source,
            $exceptionClass ?? '',
            $file ?? '',
            $line ?? '',
            $normalizedMessage,
        ]));
    }

    private function normalizeMessage(string $message): string
    {
        $stripped = preg_replace('/\b\d+\b/', '?', trim($message)) ?? $message;
        $stripped = preg_replace('/\s+/', ' ', $stripped) ?? $stripped;
        return mb_strimwidth($stripped, 0, 500, '');
    }

    private function normalizeFilePath(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $base = base_path();
        if (str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), '/\\');
        }

        return $path;
    }

    private function normalizeLevel(string $level): string
    {
        $level = strtolower($level);
        return in_array($level, ['error', 'critical', 'alert', 'emergency'], true) ? $level : 'error';
    }

    private function sanitizeContext(array $context): array
    {
        unset($context['exception'], $context['__error_log_recorder_skip']);

        $encoded = json_encode($context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false || strlen($encoded) > 64_000) {
            return ['_truncated' => true];
        }

        return $context;
    }

    private function safeUrl(): ?string
    {
        try {
            return app()->runningInConsole() ? null : mb_strimwidth((string) Request::fullUrl(), 0, 500, '');
        } catch (Throwable) {
            return null;
        }
    }

    private function safeMethod(): ?string
    {
        try {
            return app()->runningInConsole() ? null : Request::method();
        } catch (Throwable) {
            return null;
        }
    }

    private function safeUserId(): ?int
    {
        try {
            return Auth::id();
        } catch (Throwable) {
            return null;
        }
    }

    private function safeIp(): ?string
    {
        try {
            return app()->runningInConsole() ? null : Request::ip();
        } catch (Throwable) {
            return null;
        }
    }

    private function pruneOccurrences(int $groupId): void
    {
        try {
            $keepIds = ErrorLogOccurrence::query()
                ->where('group_id', $groupId)
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::MAX_OCCURRENCES_PER_GROUP)
                ->pluck('id');

            if ($keepIds->isEmpty()) {
                return;
            }

            ErrorLogOccurrence::query()
                ->where('group_id', $groupId)
                ->whereNotIn('id', $keepIds)
                ->delete();
        } catch (QueryException) {
            // Best-effort prune; ignore failures.
        }
    }
}
