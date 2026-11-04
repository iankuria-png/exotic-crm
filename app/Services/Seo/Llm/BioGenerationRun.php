<?php

namespace App\Services\Seo\Llm;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Request-local execution state. Only the CRM interactive bio path opts in. */
class BioGenerationRun
{
    public readonly string $id;

    private readonly Closure $clock;

    private readonly float $started;

    private readonly float $deadline;

    private int $requestCount = 0;

    private array $attempts = [];

    private array $stages = [];

    private array $memo = [];

    private bool $finished = false;

    private ?string $failedStage = null;

    public function __construct(
        private readonly float $seconds = 30,
        private readonly float $attemptSeconds = 12,
        private readonly int $maxAttempts = 2,
        private readonly float $reserveSeconds = 2,
        ?Closure $clock = null,
    ) {
        $this->id = (string) Str::uuid();
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->started = ($this->clock)();
        $this->deadline = $this->started + max(0, $seconds);
    }

    public static function fromConfig(): self
    {
        return new self(
            seconds: max(5, min(45, (float) config('services.seo_engine.interactive.deadline_seconds', 30))),
            attemptSeconds: max(1, min(20, (float) config('services.seo_engine.interactive.attempt_seconds', 12))),
        );
    }

    public function remainingSeconds(): float
    {
        return max(0, $this->deadline - ($this->clock)());
    }

    public function readTimeout(): float
    {
        return max(0, min(3, $this->remainingSeconds() - $this->reserveSeconds));
    }

    public function canAttempt(): bool
    {
        return $this->requestCount < $this->maxAttempts
            && $this->remainingSeconds() > $this->reserveSeconds + 0.05;
    }

    public function canRewrite(): bool
    {
        return $this->canAttempt() && $this->remainingSeconds() >= 8 + $this->reserveSeconds;
    }

    public function stage(string $name, callable $work): mixed
    {
        $start = ($this->clock)();
        try {
            return $work();
        } catch (Throwable $error) {
            $this->failedStage = $name;
            throw $error;
        } finally {
            $this->stages[$name] = ($this->stages[$name] ?? 0) + $this->elapsed($start);
        }
    }

    public function remember(string $key, callable $work): mixed
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $work();
        }

        return $this->memo[$key];
    }

    public function send(
        PendingRequest $request, string $url, array $payload,
        string $provider, string $model, string $credential,
    ): Response {
        if (! $this->canAttempt()) {
            throw new GenerationBudgetExceededException('Interactive bio request budget exhausted.');
        }

        $mode = array_intersect_key($payload, array_flip(['thinking', 'reasoning', 'provider']));
        $key = 'seo_bio_circuit_'.hash('sha256', $provider.'|'.$model.'|'.$credential.'|'.json_encode($mode));
        if ($this->cacheRead($key.'_open')) {
            $this->attempts[] = ['provider' => $provider, 'model' => $this->safeLabel($model), 'status' => 'skipped', 'reason' => 'circuit_open', 'elapsed_ms' => 0];
            throw new ProviderRequestException('circuit_open');
        }

        $timeout = min($this->attemptSeconds, $this->remainingSeconds() - $this->reserveSeconds);
        if ($timeout <= 0.05) {
            throw new GenerationBudgetExceededException('Interactive bio deadline reached.');
        }
        $this->requestCount++;
        $start = ($this->clock)();
        $meta = ['provider' => $provider, 'model' => $this->safeLabel($model), 'status' => 'failed'];
        try {
            $response = $request->withOptions(['timeout' => $timeout, 'connect_timeout' => min(3, $timeout)])
                ->post($url, $payload);
            $meta += $this->responseMetadata($response);

            if ($response->failed()) {
                throw new ProviderRequestException('http', $response->status());
            }
            if (in_array($meta['finish_reason'], ['length', 'MAX_TOKENS', 'max_tokens'], true)) {
                throw new ProviderRequestException('truncated');
            }
            if ($meta['refused']) {
                throw new ProviderRequestException('refused');
            }
            if ($meta['visible_characters'] === 0) {
                throw new ProviderRequestException('empty');
            }
            $noThinking = data_get($payload, 'reasoning.enabled') === false || data_get($payload, 'thinking.type') === 'disabled';
            if ($noThinking && $meta['reasoning_tokens'] > 0) {
                throw new ProviderRequestException('reasoning_not_disabled');
            }
            $meta['status'] = 'success';
            $this->cacheReset($key);

            return $response;
        } catch (Throwable $error) {
            $safe = $error instanceof ProviderRequestException ? $error
                : ($error instanceof ConnectionException
                    ? new ProviderRequestException(preg_match('/timed?\s*out|timeout|cURL error 28/i', $error->getMessage()) ? 'timeout' : 'connection')
                    : new ProviderRequestException('internal'));
            $meta['reason'] = $safe->category;
            if ($safe->affectsAvailability()) {
                $this->cacheFailure($key);
            }
            throw $safe;
        } finally {
            $meta['elapsed_ms'] = $this->elapsed($start);
            $this->attempts[] = $meta;
        }
    }

    public function summary(string $status = 'success'): array
    {
        return ['request_id' => $this->id, 'status' => $status, 'total_ms' => $this->elapsed($this->started),
            'http_attempts' => $this->requestCount, 'stage_ms' => $this->stages, 'failed_stage' => $this->failedStage, 'attempts' => $this->attempts];
    }

    public function finish(string $status): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        try {
            Log::channel('seo_performance')->info('seo.bio_performance', $this->summary($status));
        } catch (Throwable) {
            // A missing/unwritable telemetry channel must not break a draft.
        }
    }

    private function responseMetadata(Response $response): array
    {
        $json = (array) $response->json();
        $choice = (array) ($json['choices'][0] ?? []);
        $content = data_get($choice, 'message.content') ?? $choice['text'] ?? data_get($json, 'candidates.0.content.parts') ?? $json['content'] ?? '';
        if (is_array($content)) {
            $content = implode('', array_map(static fn ($part): string => is_string($part) ? $part : (string) ($part['text'] ?? $part['content'] ?? ''), $content));
        }
        $finish = (string) ($choice['finish_reason'] ?? data_get($json, 'candidates.0.finishReason') ?? $json['stop_reason'] ?? '');
        $input = (int) (data_get($json, 'usage.prompt_tokens') ?? data_get($json, 'usageMetadata.promptTokenCount') ?? data_get($json, 'usage.input_tokens') ?? 0);
        $output = (int) (data_get($json, 'usage.completion_tokens') ?? data_get($json, 'usageMetadata.candidatesTokenCount') ?? data_get($json, 'usage.output_tokens') ?? 0);
        $reasoning = (int) (data_get($json, 'usage.completion_tokens_details.reasoning_tokens') ?? data_get($json, 'usageMetadata.thoughtsTokenCount') ?? 0);

        return ['http_status' => $response->status(), 'actual_model' => $this->safeLabel((string) ($json['model'] ?? '')),
            'upstream_provider' => $this->safeLabel((string) ($json['provider'] ?? data_get($choice, 'openrouter_metadata.provider_name') ?? '')),
            'finish_reason' => $this->safeLabel($finish), 'input_tokens' => $input, 'output_tokens' => $output,
            'reasoning_tokens' => $reasoning, 'visible_tokens' => isset($json['usageMetadata']) ? $output : max(0, $output - $reasoning),
            'visible_characters' => mb_strlen(trim((string) $content)),
            'refused' => ! empty(data_get($choice, 'message.refusal')) || in_array($finish, ['content_filter', 'SAFETY', 'RECITATION'], true)];
    }

    private function safeLabel(string $value): string
    {
        return preg_match('/^[a-zA-Z0-9_~.\/: -]{0,140}$/D', $value) ? $value : 'unavailable';
    }

    private function elapsed(float $start): int
    {
        return max(0, (int) round((($this->clock)() - $start) * 1000));
    }

    private function cacheRead(string $key): bool
    {
        try {
            return (bool) Cache::get($key, false);
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheFailure(string $key): void
    {
        try {
            Cache::add($key.'_failures', 0, 120);
            if (Cache::increment($key.'_failures') >= 2) {
                Cache::put($key.'_open', true, 60);
            }
        } catch (Throwable) {
            // Infrastructure monitoring is best effort.
        }
    }

    private function cacheReset(string $key): void
    {
        try {
            Cache::forget($key.'_failures');
            Cache::forget($key.'_open');
        } catch (Throwable) {
        }
    }
}
