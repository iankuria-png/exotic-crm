<?php

namespace App\Services;

use App\Models\ErrorLogGroup;
use App\Models\ErrorLogOccurrence;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;
use Throwable;

class ErrorLogRecorder
{
    public const MAX_OCCURRENCES_PER_GROUP = 20;

    private bool $recording = false;

    /** @var \WeakMap<Throwable, true> */
    private \WeakMap $markedExceptions;

    public function __construct()
    {
        $this->markedExceptions = new \WeakMap;
    }

    public function markException(Throwable $exception): void
    {
        $this->markedExceptions[$exception] = true;
    }

    public function takeMarkedException(Throwable $exception): bool
    {
        if (! isset($this->markedExceptions[$exception])) {
            return false;
        }

        unset($this->markedExceptions[$exception]);

        return true;
    }

    public function flushMarkedExceptions(): void
    {
        foreach ($this->markedExceptions as $exception => $_) {
            unset($this->markedExceptions[$exception]);
            $this->recordException($exception);
        }
    }

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
                $now = now();

                DB::table('error_log_groups')->upsert([
                    [
                        'signature' => $signature,
                        'level' => $this->normalizeLevel($level),
                        'exception_class' => $exceptionClass,
                        'message' => $resolvedMessage,
                        'file' => $file,
                        'line' => $line,
                        'source' => $source,
                        'occurrence_count' => 1,
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ], ['signature'], [
                    'occurrence_count' => DB::raw('occurrence_count + 1'),
                    'last_seen_at' => $now,
                    'resolved_at' => DB::raw('NULL'),
                    'resolved_by' => DB::raw('NULL'),
                    'updated_at' => $now,
                ]);

                $group = ErrorLogGroup::query()->where('signature', $signature)->firstOrFail();

                $occurrence = ErrorLogOccurrence::create([
                    'group_id' => $group->id,
                    'occurred_at' => now(),
                    'trace' => $trace,
                    'context' => $this->sanitizeContext($this->withRequestId($context)),
                    'url' => $this->safeUrl(),
                    'method' => $this->safeMethod(),
                    'user_id' => $this->safeUserId(),
                    'ip' => $this->safeIp(),
                    'platform_id' => null,
                ]);

                $group->last_occurrence_id = $occurrence->id;
                $group->saveQuietly();
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

    /**
     * Stamp the request correlation id (set by AttachRequestId) onto the
     * occurrence context so a logged error is traceable to the id the user
     * was shown. A caller-supplied request_id always wins (e.g. client-error
     * ingest passes the id the browser actually saw).
     */
    private function withRequestId(array $context): array
    {
        if (array_key_exists('request_id', $context)) {
            return $context;
        }

        try {
            if (! app()->runningInConsole()) {
                $requestId = Request::instance()->attributes->get('request_id');
                if ($requestId) {
                    $context['request_id'] = $requestId;
                }
            }
        } catch (Throwable) {
            // No request context available — leave it out.
        }

        return $context;
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
}
