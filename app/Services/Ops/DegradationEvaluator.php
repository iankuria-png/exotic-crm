<?php

namespace App\Services\Ops;

use App\Jobs\SendSystemDegradationAlertJob;
use App\Models\SystemIncident;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns a vitals sample into a degradation level.
 *
 * Escalation and recovery are deliberately asymmetric. A level rises only after
 * a signal has breached its threshold for two consecutive samples — one sample
 * is noise — and falls only after five consecutive clear ones, and then by a
 * single step. A system oscillating around a boundary therefore settles instead
 * of flapping, and a shed that has just been lifted is not immediately
 * reapplied.
 */
class DegradationEvaluator
{
    private const STREAK_CACHE_KEY = 'ops.degradation.streak';
    private const STATE_TTL_MINUTES = 120;

    public function __construct(
        private readonly OperationsSettingsService $settings,
    ) {
    }

    /**
     * Evaluate one sample and persist the resulting state.
     *
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed> the state that was written
     */
    public function evaluate(array $sample): array
    {
        $state = $this->currentState();
        $currentLevel = (int) ($state['sampled_level'] ?? LoadShedder::LEVEL_NORMAL);

        $assessment = $this->assess($sample['signals'] ?? []);
        $candidate = $assessment['level'];

        $escalateSamples = max(1, $this->settings->integer('ops.hysteresis.escalate_samples'));
        $recoverSamples = max(1, $this->settings->integer('ops.hysteresis.recover_samples'));

        $streak = $this->readStreak();
        $newLevel = $currentLevel;

        if ($candidate > $currentLevel) {
            $streak['clear'] = 0;
            $streak['breach'] = ($streak['breach_level'] ?? null) === $candidate
                ? (int) $streak['breach'] + 1
                : 1;
            $streak['breach_level'] = $candidate;

            if ($streak['breach'] >= $escalateSamples) {
                $newLevel = $candidate;
                $streak['breach'] = 0;
                $streak['breach_level'] = null;
            }
        } elseif ($candidate < $currentLevel) {
            $streak['breach'] = 0;
            $streak['breach_level'] = null;
            $streak['clear'] = (int) ($streak['clear'] ?? 0) + 1;

            if ($streak['clear'] >= $recoverSamples) {
                // One step at a time, never straight to Normal: a system that
                // has just been at Limp deserves a sample at Cautious before it
                // is trusted with everything again.
                $newLevel = max($candidate, $currentLevel - 1);
                $streak['clear'] = 0;
            }
        } else {
            $streak = ['breach' => 0, 'breach_level' => null, 'clear' => 0];
        }

        $this->writeStreak($streak);

        $transitioned = $newLevel !== $currentLevel;

        $state = $this->composeState($state, $sample, $assessment, $newLevel, $transitioned);
        $this->writeState($state);

        if ($transitioned) {
            $this->recordTransition($currentLevel, $newLevel, $sample, $assessment, $state);
        }

        return $state;
    }

    /**
     * Force a level manually. Always expires — a forced level with no expiry is
     * how a system ends up shed for a week after an incident nobody closed out.
     *
     * @return array<string, mixed>
     */
    public function force(int $level, string $reason, int $expiresInMinutes, ?int $actorId): array
    {
        $state = $this->currentState();
        $previous = (int) ($state['level'] ?? LoadShedder::LEVEL_NORMAL);

        $state['level'] = $level;
        $state['forced'] = true;
        $state['forced_expires_at'] = now()->addMinutes($expiresInMinutes)->toIso8601String();
        $state['forced_reason'] = $reason;
        $state['forced_by'] = $actorId;
        $state['since'] = now()->toIso8601String();
        $state['enforcement'] = $this->settings->boolean('ops.enforcement.enabled');

        $this->writeState($state);

        if ($previous !== $level) {
            $this->openIncident([
                'from_level' => $previous,
                'to_level' => $level,
                'trigger_signal' => 'manual_override',
                'trigger_value' => $level,
                'threshold' => null,
                'origin' => SystemIncident::ORIGIN_MANUAL,
                'actor_id' => $actorId,
                'snapshot' => ['reason' => $reason, 'expires_at' => $state['forced_expires_at']],
            ], $previous, $level);

            $this->dispatchAlert($previous, $level, 'manual_override', $level, null);
        }

        return $state;
    }

    /**
     * Clear a manual override, returning control to the sampled level.
     *
     * @return array<string, mixed>
     */
    public function release(?int $actorId): array
    {
        $state = $this->currentState();
        $previous = (int) ($state['level'] ?? LoadShedder::LEVEL_NORMAL);
        $sampled = (int) ($state['sampled_level'] ?? LoadShedder::LEVEL_NORMAL);

        $state['level'] = $sampled;
        $state['forced'] = false;
        $state['forced_expires_at'] = null;
        $state['forced_reason'] = null;
        $state['forced_by'] = null;
        $state['released_by'] = $actorId;
        $state['since'] = now()->toIso8601String();

        $this->writeState($state);

        if ($previous !== $sampled) {
            $this->resolveOpenIncidents($sampled);
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    public function currentState(): array
    {
        try {
            $state = Cache::get(LoadShedder::STATE_CACHE_KEY);
        } catch (\Throwable) {
            return [];
        }

        return is_array($state) ? $state : [];
    }

    /**
     * The instantaneous level a sample implies, before hysteresis.
     *
     * @param  array<int, array<string, mixed>>  $signals
     * @return array{level:int, signal:string|null, value:float|null, threshold:float|null, breaching:array<int, string>}
     */
    public function assess(array $signals): array
    {
        $level = LoadShedder::LEVEL_NORMAL;
        $signalKey = null;
        $value = null;
        $threshold = null;
        $breaching = [];

        $criticalTick = $this->settings->integer('ops.threshold.scheduler_tick_seconds.critical');

        foreach ($signals as $signal) {
            // An unavailable signal is ignored entirely. Process counting is
            // optional on shared hosting, and the rules still function on
            // scheduler tick duration and queue depth alone.
            if (! ($signal['available'] ?? false)) {
                continue;
            }

            $signalValue = (float) $signal['value'];
            $candidate = LoadShedder::LEVEL_NORMAL;
            $against = null;

            if ($signal['key'] === 'php_processes' && $signal['ceiling'] !== null && $signalValue >= (float) $signal['ceiling']) {
                // The ceiling is the literal resource that ran out. Reaching it
                // is not a warning about a future outage; it is the outage.
                $candidate = LoadShedder::LEVEL_CRITICAL;
                $against = (float) $signal['ceiling'];
            } elseif ($signal['key'] === 'scheduler_tick_seconds' && $signalValue >= (float) $criticalTick) {
                $candidate = LoadShedder::LEVEL_CRITICAL;
                $against = (float) $criticalTick;
            } elseif ($signal['state'] === 'shed') {
                $candidate = LoadShedder::LEVEL_LIMP;
                $against = (float) $signal['shed'];
            } elseif ($signal['state'] === 'watch') {
                $candidate = LoadShedder::LEVEL_CAUTIOUS;
                $against = (float) $signal['watch'];
            }

            if ($candidate > LoadShedder::LEVEL_NORMAL) {
                $breaching[] = (string) $signal['key'];
            }

            if ($candidate > $level) {
                $level = $candidate;
                $signalKey = (string) $signal['key'];
                $value = $signalValue;
                $threshold = $against;
            }
        }

        return [
            'level' => $level,
            'signal' => $signalKey,
            'value' => $value,
            'threshold' => $threshold,
            'breaching' => $breaching,
        ];
    }

    /**
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $sample
     * @param  array{level:int, signal:string|null, value:float|null, threshold:float|null, breaching:array<int, string>}  $assessment
     * @return array<string, mixed>
     */
    private function composeState(array $previous, array $sample, array $assessment, int $sampledLevel, bool $transitioned): array
    {
        $forced = (bool) ($previous['forced'] ?? false);
        $forcedExpiresAt = $previous['forced_expires_at'] ?? null;

        if ($forced && $forcedExpiresAt !== null && strtotime((string) $forcedExpiresAt) < now()->timestamp) {
            // Expiry restores automatic evaluation rather than dropping to
            // Normal: an incident still in progress must not read as healthy
            // because nobody renewed the override.
            $forced = false;
            $forcedExpiresAt = null;
        }

        $effective = $forced ? (int) ($previous['level'] ?? $sampledLevel) : $sampledLevel;

        return [
            'level' => $effective,
            'level_label' => LoadShedder::label($effective),
            'sampled_level' => $sampledLevel,
            'sampled_level_label' => LoadShedder::label($sampledLevel),
            'forced' => $forced,
            'forced_expires_at' => $forcedExpiresAt,
            'forced_reason' => $forced ? ($previous['forced_reason'] ?? null) : null,
            'forced_by' => $forced ? ($previous['forced_by'] ?? null) : null,
            'since' => $transitioned || ! isset($previous['since'])
                ? now()->toIso8601String()
                : $previous['since'],
            'trigger_signal' => $assessment['signal'],
            'trigger_value' => $assessment['value'],
            'threshold' => $assessment['threshold'],
            'breaching' => $assessment['breaching'],
            'enforcement' => $this->settings->boolean('ops.enforcement.enabled'),
            'paused_capabilities' => LoadShedder::pausedAt($effective),
            'sampled_at' => $sample['sampled_at'] ?? now()->toIso8601String(),
            'evaluated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $sample
     * @param  array{level:int, signal:string|null, value:float|null, threshold:float|null, breaching:array<int, string>}  $assessment
     * @param  array<string, mixed>  $state
     */
    private function recordTransition(int $from, int $to, array $sample, array $assessment, array $state): void
    {
        $this->openIncident([
            'from_level' => $from,
            'to_level' => $to,
            'trigger_signal' => $assessment['signal'] ?? 'unknown',
            'trigger_value' => $assessment['value'],
            'threshold' => $assessment['threshold'],
            'origin' => SystemIncident::ORIGIN_AUTOMATIC,
            'actor_id' => null,
            'snapshot' => [
                'signals' => $sample['signals'] ?? [],
                'lanes' => $sample['lanes'] ?? [],
                'enforcement' => $state['enforcement'] ?? false,
            ],
        ], $from, $to);

        $this->dispatchAlert($from, $to, $assessment['signal'] ?? 'unknown', $assessment['value'], $assessment['threshold']);
    }

    /**
     * One row per transition, not per sample — a handful of rows a day, which
     * keeps the incident timeline cheap to read.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function openIncident(array $attributes, int $from, int $to): void
    {
        try {
            if ($to <= $from) {
                $this->resolveOpenIncidents($to);
            }

            if ($to === LoadShedder::LEVEL_NORMAL) {
                return;
            }

            SystemIncident::create($attributes + ['started_at' => now()]);
        } catch (\Throwable $exception) {
            Log::error('Unable to record a system degradation incident.', [
                'error' => $exception->getMessage(),
                'from_level' => $from,
                'to_level' => $to,
            ]);
        }
    }

    private function resolveOpenIncidents(int $toLevel): void
    {
        try {
            SystemIncident::query()
                ->whereNull('resolved_at')
                ->where('to_level', '>', $toLevel)
                ->update(['resolved_at' => now()]);
        } catch (\Throwable $exception) {
            Log::warning('Unable to resolve open system incidents.', ['error' => $exception->getMessage()]);
        }
    }

    private function dispatchAlert(int $from, int $to, string $signal, ?float $value, ?float $threshold): void
    {
        try {
            SendSystemDegradationAlertJob::dispatch($from, $to, $signal, $value, $threshold)
                ->onQueue('alerts');
        } catch (\Throwable $exception) {
            Log::warning('Unable to dispatch a degradation alert.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(array $state): void
    {
        try {
            Cache::put(LoadShedder::STATE_CACHE_KEY, $state, now()->addMinutes(self::STATE_TTL_MINUTES));
            Cache::put(LoadShedder::LEVEL_CACHE_KEY, (int) ($state['level'] ?? 0), now()->addMinutes(self::STATE_TTL_MINUTES));
        } catch (\Throwable $exception) {
            Log::error('Unable to persist the degradation state.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readStreak(): array
    {
        try {
            $streak = Cache::get(self::STREAK_CACHE_KEY);
        } catch (\Throwable) {
            $streak = null;
        }

        return is_array($streak)
            ? $streak + ['breach' => 0, 'breach_level' => null, 'clear' => 0]
            : ['breach' => 0, 'breach_level' => null, 'clear' => 0];
    }

    /**
     * @param  array<string, mixed>  $streak
     */
    private function writeStreak(array $streak): void
    {
        try {
            Cache::put(self::STREAK_CACHE_KEY, $streak, now()->addMinutes(self::STATE_TTL_MINUTES));
        } catch (\Throwable) {
            // A lost streak costs one extra sample before a transition, which
            // is the safe direction to fail in.
        }
    }
}
