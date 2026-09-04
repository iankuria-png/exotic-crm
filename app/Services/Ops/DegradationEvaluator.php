<?php

namespace App\Services\Ops;

use App\Jobs\SendSystemDegradationAlertJob;
use App\Models\SystemIncident;
use App\Services\FeatureSettingsService;
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

    /**
     * Durable mirror of the cached state.
     *
     * The cache is the read path — LoadShedder::allows() is called from every
     * scheduler skip closure and must never touch the database — but a cache
     * entry is not a system of record. On 4 Sep production lost its entry
     * between samples and the level silently reset to Normal: no transition was
     * recorded, no incident was resolved, and the next breach read as a fresh
     * `Normal -> Critical`. The timeline ended up with seven incidents open at
     * once and five identical transitions with no recovery between them.
     *
     * These rows are runtime state, not settings, so they sit under an
     * `ops.runtime.` prefix that the registry does not declare and the tuning UI
     * therefore never shows.
     */
    private const DURABLE_STATE_KEY = 'ops.runtime.degradation_state';
    private const DURABLE_STREAK_KEY = 'ops.runtime.degradation_streak';

    public function __construct(
        private readonly OperationsSettingsService $settings,
        private readonly FeatureSettingsService $featureSettings,
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

        // Self-healing, every sample rather than only on the way down. An
        // incident is "open" while the platform is at or above its level; if we
        // are now below it, it ended — whether we walked down through the
        // levels or lost our state and came back. Without this, any gap in
        // continuity leaves a row that reads "Still elevated" forever.
        $this->reconcileOpenIncidents((int) $state['level']);

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

            if (is_array($state)) {
                return $state;
            }
        } catch (\Throwable) {
            // Fall through to the durable copy.
        }

        // Cache cold or evicted. Rehydrate rather than starting from Normal,
        // which would lose an in-progress incident and its streak.
        $state = $this->readDurable(self::DURABLE_STATE_KEY);

        if ($state !== []) {
            $this->primeCache($state);
        }

        return $state;
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

            if ($signal['key'] === 'php_processes'
                && $signal['ceiling'] !== null
                && ($signal['ceiling_enforced'] ?? false)
                && $signalValue >= (float) $signal['ceiling']
            ) {
                // The ceiling is the literal resource that ran out. Reaching it
                // is not a warning about a future outage; it is the outage.
                //
                // But only once somebody has confirmed the number with the host.
                // An UNVERIFIED ceiling is a guess, and a guess must never drive
                // the loudest state in the system: on 4 Sep the board sat at
                // Critical all day reading 69 against a guessed 60, while every
                // other signal was comfortably green. That is the same failure
                // as the old always-healthy heartbeat, only inverted — and a
                // board nobody believes is worth nothing. While the ceiling is
                // unverified this signal still escalates through its own watch
                // and shed thresholds, which are absolute process counts and
                // mean something on their own; it just cannot reach Critical.
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
        $this->reconcileOpenIncidents($toLevel);
    }

    /**
     * Close every open incident whose level the platform has dropped below.
     *
     * Idempotent and cheap — usually a no-op update matching zero rows — so it
     * is safe to run on every sample, which is what makes it a repair for state
     * that was lost rather than only a step in an orderly recovery.
     */
    private function reconcileOpenIncidents(int $currentLevel): void
    {
        try {
            $stale = SystemIncident::query()
                ->whereNull('resolved_at')
                ->where('to_level', '>', $currentLevel)
                ->exists();

            if (! $stale) {
                return;
            }

            SystemIncident::query()
                ->whereNull('resolved_at')
                ->where('to_level', '>', $currentLevel)
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
        $this->primeCache($state);
        $this->writeDurable(self::DURABLE_STATE_KEY, $state);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function primeCache(array $state): void
    {
        try {
            Cache::put(LoadShedder::STATE_CACHE_KEY, $state, now()->addMinutes(self::STATE_TTL_MINUTES));
            Cache::put(LoadShedder::LEVEL_CACHE_KEY, (int) ($state['level'] ?? 0), now()->addMinutes(self::STATE_TTL_MINUTES));
        } catch (\Throwable $exception) {
            Log::error('Unable to cache the degradation state.', ['error' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readDurable(string $key): array
    {
        try {
            $value = $this->featureSettings->get($key, null);
        } catch (\Throwable) {
            return [];
        }

        return is_array($value) ? $value : [];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function writeDurable(string $key, array $value): void
    {
        try {
            $this->featureSettings->set($key, $value, null);
        } catch (\Throwable $exception) {
            // Losing the durable copy costs incident continuity across a cache
            // eviction, not correctness of the current sample.
            Log::warning('Unable to persist degradation runtime state.', [
                'key' => $key,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readStreak(): array
    {
        $default = ['breach' => 0, 'breach_level' => null, 'clear' => 0];

        try {
            $streak = Cache::get(self::STREAK_CACHE_KEY);

            if (is_array($streak)) {
                return $streak + $default;
            }
        } catch (\Throwable) {
            // Fall through to the durable copy.
        }

        $streak = $this->readDurable(self::DURABLE_STREAK_KEY);

        return $streak !== [] ? $streak + $default : $default;
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

        $this->writeDurable(self::DURABLE_STREAK_KEY, $streak);
    }
}
