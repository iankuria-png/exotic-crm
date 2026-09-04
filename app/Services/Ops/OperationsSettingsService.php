<?php

namespace App\Services\Ops;

use App\Services\AuditService;
use App\Services\FeatureSettingsService;
use Illuminate\Support\Facades\Cache;

/**
 * Reads and writes the operations knobs declared in the registry.
 *
 * Two rules hold this together. A value is validated against the registry's
 * bounds and REJECTED naming the bound it violated — never silently clamped,
 * because a clamp hides that the operator's intent was not honoured. And every
 * write is audited with before and after state, so a tuning change made during
 * an incident is traceable to a person afterwards.
 */
class OperationsSettingsService
{
    /**
     * Settings are read on every scheduler tick and on the sampler's hot path,
     * so they are cached briefly. The window is short enough that a change is
     * in force "on the next tick" as promised, and long enough that reading a
     * dozen keys is not a dozen queries every minute.
     */
    private const CACHE_KEY = 'ops.settings.resolved';
    private const CACHE_SECONDS = 20;

    public function __construct(
        private readonly OperationsSettingsRegistry $registry,
        private readonly FeatureSettingsService $featureSettings,
        private readonly AuditService $auditService,
    ) {
    }

    /**
     * All settings resolved to their effective values, defaults included.
     *
     * @return array<string, mixed>
     */
    public function resolved(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->readAll());
        } catch (\Throwable) {
            // A cache outage must not take the scheduler's option builders with
            // it — fall back to reading straight through.
            return $this->readAll();
        }
    }

    public function value(string $key): mixed
    {
        $definition = $this->registry->find($key);

        if ($definition === null) {
            return null;
        }

        return $this->resolved()[$key] ?? $definition['default'];
    }

    public function integer(string $key): int
    {
        return (int) $this->value($key);
    }

    public function boolean(string $key): bool
    {
        return (bool) $this->value($key);
    }

    public function string(string $key): string
    {
        return (string) $this->value($key);
    }

    /**
     * Apply a batch of updates.
     *
     * @param  array<int, array{key:string, value:mixed}>  $updates
     * @return array{updated:int, changes:array<int, array<string, mixed>>}
     *
     * @throws OperationsSettingValidationException
     */
    public function update(array $updates, ?int $actorId, ?string $actorRole, ?string $ipAddress = null): array
    {
        $prepared = [];

        // Validate the whole batch before writing any of it, so a partially
        // applied batch can never leave thresholds inconsistent with each other.
        foreach ($updates as $update) {
            $key = (string) ($update['key'] ?? '');
            $definition = $this->registry->find($key);

            if ($definition === null) {
                throw new OperationsSettingValidationException($key, sprintf('`%s` is not a known operations setting.', $key));
            }

            if (! $this->registry->canWriteGroup($definition['group'], $actorRole)) {
                throw new OperationsSettingValidationException(
                    $key,
                    sprintf('Your role may not change %s settings.', $this->registry->groups()[$definition['group']]['label']),
                    403
                );
            }

            $prepared[] = [
                'definition' => $definition,
                'value' => $this->cast($definition, $update['value'] ?? null),
            ];
        }

        $this->assertThresholdOrdering($prepared);

        $changes = [];

        foreach ($prepared as $entry) {
            $definition = $entry['definition'];
            $key = $definition['key'];
            $before = $this->value($key);

            if ($before === $entry['value']) {
                continue;
            }

            $this->featureSettings->set($key, $entry['value'], $actorId);
            $this->forget();

            $changes[] = [
                'key' => $key,
                'label' => $definition['label'],
                'group' => $definition['group'],
                'before' => $before,
                'after' => $entry['value'],
            ];

            $this->audit($definition, $before, $entry['value'], $actorId, $ipAddress);
        }

        return ['updated' => count($changes), 'changes' => $changes];
    }

    /**
     * Return a setting to its declared default by removing the stored override.
     *
     * @throws OperationsSettingValidationException
     */
    public function reset(string $key, ?int $actorId, ?string $actorRole, ?string $ipAddress = null): mixed
    {
        $definition = $this->registry->find($key);

        if ($definition === null) {
            throw new OperationsSettingValidationException($key, sprintf('`%s` is not a known operations setting.', $key));
        }

        if (! $this->registry->canWriteGroup($definition['group'], $actorRole)) {
            throw new OperationsSettingValidationException(
                $key,
                sprintf('Your role may not change %s settings.', $this->registry->groups()[$definition['group']]['label']),
                403
            );
        }

        $before = $this->value($key);
        $this->featureSettings->set($key, $definition['default'], $actorId);
        $this->forget();

        if ($before !== $definition['default']) {
            $this->audit($definition, $before, $definition['default'], $actorId, $ipAddress);
        }

        return $definition['default'];
    }

    public function forget(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable) {
            // Nothing to do; the entry expires on its own within seconds.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readAll(): array
    {
        $resolved = [];

        foreach ($this->registry->all() as $key => $definition) {
            $stored = $this->featureSettings->get($key, null);

            $resolved[$key] = $stored === null
                ? $definition['default']
                : $this->coerce($definition, $stored);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $definition
     *
     * @throws OperationsSettingValidationException
     */
    private function cast(array $definition, mixed $value): mixed
    {
        $key = $definition['key'];

        switch ($definition['type']) {
            case OperationsSettingsRegistry::TYPE_BOOLEAN:
                if (is_bool($value)) {
                    return $value;
                }

                if (in_array($value, [0, 1, '0', '1', 'true', 'false', true, false], true)) {
                    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                }

                throw new OperationsSettingValidationException($key, sprintf('%s must be true or false.', $definition['label']));

            case OperationsSettingsRegistry::TYPE_TIME:
                if (! is_string($value) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value)) {
                    throw new OperationsSettingValidationException($key, sprintf('%s must be a 24-hour time such as 22:00.', $definition['label']));
                }

                return $value;

            default:
                if (! is_numeric($value) || (int) $value != $value) {
                    throw new OperationsSettingValidationException($key, sprintf('%s must be a whole number.', $definition['label']));
                }

                $intValue = (int) $value;

                if ($definition['min'] !== null && $intValue < $definition['min']) {
                    throw new OperationsSettingValidationException($key, sprintf(
                        '%s must be at least %d%s.',
                        $definition['label'],
                        $definition['min'],
                        $definition['unit'] ? ' '.$definition['unit'] : ''
                    ));
                }

                if ($definition['max'] !== null && $intValue > $definition['max']) {
                    throw new OperationsSettingValidationException($key, sprintf(
                        '%s must be at most %d%s.',
                        $definition['label'],
                        $definition['max'],
                        $definition['unit'] ? ' '.$definition['unit'] : ''
                    ));
                }

                return $intValue;
        }
    }

    /**
     * A stored value that no longer satisfies its declaration — because the
     * bounds were tightened in a later release — falls back to the default
     * rather than propagating an out-of-range number into the scheduler.
     *
     * @param  array<string, mixed>  $definition
     */
    private function coerce(array $definition, mixed $stored): mixed
    {
        try {
            return $this->cast($definition, $stored);
        } catch (OperationsSettingValidationException) {
            return $definition['default'];
        }
    }

    /**
     * A watch threshold above its own shed threshold would mean the platform
     * enters Limp before it ever enters Cautious. Caught here rather than
     * discovered during an incident.
     *
     * @param  array<int, array{definition:array<string, mixed>, value:mixed}>  $prepared
     *
     * @throws OperationsSettingValidationException
     */
    private function assertThresholdOrdering(array $prepared): void
    {
        $pending = [];
        foreach ($prepared as $entry) {
            $pending[$entry['definition']['key']] = $entry['value'];
        }

        foreach ($prepared as $entry) {
            $key = $entry['definition']['key'];

            if (! preg_match('/^ops\.threshold\.([a-z_]+)\.(watch|shed)$/', $key, $matches)) {
                continue;
            }

            $signal = $matches[1];
            $watchKey = "ops.threshold.{$signal}.watch";
            $shedKey = "ops.threshold.{$signal}.shed";

            $watch = (int) ($pending[$watchKey] ?? $this->value($watchKey));
            $shed = (int) ($pending[$shedKey] ?? $this->value($shedKey));

            if ($watch > $shed) {
                throw new OperationsSettingValidationException($key, sprintf(
                    'The watch threshold for %s (%d) must not be above its shed threshold (%d).',
                    str_replace('_', ' ', $signal),
                    $watch,
                    $shed
                ));
            }

            // The process ceiling is a hard stop: reaching it IS the outage, so
            // it must sit at or above every softer threshold. Configured the
            // other way round — as production was on 4 Sep, with watch 26, shed
            // 100 and a ceiling of 60 — the ceiling fires first and Limp becomes
            // unreachable, so the platform can only ever be Normal, Cautious or
            // Critical on its most important signal. Nothing told anyone.
            if ($signal === 'php_processes') {
                $ceilingKey = 'ops.threshold.php_processes.ceiling';
                $ceiling = (int) ($pending[$ceilingKey] ?? $this->value($ceilingKey));

                if ($shed > $ceiling) {
                    throw new OperationsSettingValidationException($key, sprintf(
                        'The shed threshold for PHP processes (%d) must not be above the process ceiling (%d) — the ceiling would trip first and Limp could never be reached.',
                        $shed,
                        $ceiling
                    ));
                }
            }
        }

        // A ceiling lowered below an existing shed threshold is the same
        // conflict approached from the other side.
        foreach ($prepared as $entry) {
            if ($entry['definition']['key'] !== 'ops.threshold.php_processes.ceiling') {
                continue;
            }

            $ceiling = (int) $entry['value'];
            $shed = (int) ($pending['ops.threshold.php_processes.shed'] ?? $this->value('ops.threshold.php_processes.shed'));

            if ($ceiling < $shed) {
                throw new OperationsSettingValidationException($entry['definition']['key'], sprintf(
                    'The process ceiling (%d) must not be below the PHP processes shed threshold (%d) — the ceiling would trip first and Limp could never be reached.',
                    $ceiling,
                    $shed
                ));
            }
        }
    }

    /**
     * Conflicts in the CURRENTLY STORED configuration.
     *
     * Validation above rejects a bad combination at the moment somebody tries
     * to save it, which does nothing about a bad combination already in the
     * database — and production is in exactly that position. These are reported
     * on the board so the misordering is visible rather than displayed deadpan
     * as three numbers that do not reconcile.
     *
     * @return array<int, array{key:string, message:string}>
     */
    public function configurationWarnings(): array
    {
        $warnings = [];

        $watch = $this->integer('ops.threshold.php_processes.watch');
        $shed = $this->integer('ops.threshold.php_processes.shed');
        $ceiling = $this->integer('ops.threshold.php_processes.ceiling');

        if ($shed > $ceiling) {
            $warnings[] = [
                'key' => 'ops.threshold.php_processes.shed',
                'message' => sprintf(
                    'PHP processes: shed (%d) is above the ceiling (%d), so the ceiling trips first and Limp can never be reached on this signal. Lower shed to at most %d, or raise the ceiling.',
                    $shed,
                    $ceiling,
                    $ceiling
                ),
            ];
        }

        if ($watch > $shed) {
            $warnings[] = [
                'key' => 'ops.threshold.php_processes.watch',
                'message' => sprintf('PHP processes: watch (%d) is above shed (%d), so Limp would be entered before Cautious.', $watch, $shed),
            ];
        }

        if (! $this->boolean('ops.threshold.php_processes.ceiling_verified')) {
            $warnings[] = [
                'key' => 'ops.threshold.php_processes.ceiling_verified',
                'message' => sprintf(
                    'The process ceiling of %d has not been confirmed with the host, so it is shown for context but cannot escalate the platform to Critical. Confirm the real cPanel entry-process limit to enable it.',
                    $ceiling
                ),
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function audit(array $definition, mixed $before, mixed $after, ?int $actorId, ?string $ipAddress): void
    {
        $this->auditService->recordSystem([
            'entity_type' => 'ops_setting',
            // FeatureSetting rows are keyed by string, and recordSystem needs a
            // positive integer, so the key is hashed into a stable surrogate.
            // The human-readable key travels in the state payload.
            'entity_id' => $this->surrogateId($definition['key']),
            'action' => 'ops_setting_update',
            'actor_id' => $actorId,
            'before_state' => ['key' => $definition['key'], 'value' => $before],
            'after_state' => ['key' => $definition['key'], 'value' => $after],
            'reason' => sprintf('%s changed via Settings → Operations', $definition['label']),
            'ip_address' => $ipAddress,
        ]);
    }

    private function surrogateId(string $key): int
    {
        return (int) (hexdec(substr(md5($key), 0, 8)) % 2147483647) + 1;
    }
}
