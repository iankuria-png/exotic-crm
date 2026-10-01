<?php

namespace App\Services\DbScanner;

use App\Models\DbScanSetting;
use Illuminate\Validation\ValidationException;

/**
 * Effective scanner limits: the admin's stored values clamped to the tested
 * envelope in config/db_scanner.php. Limits can only be lowered.
 */
class ScannerSettings
{
    private ?DbScanSetting $row = null;

    public function row(bool $fresh = false): DbScanSetting
    {
        if ($fresh || $this->row === null) {
            $this->row = DbScanSetting::current();
        }

        return $this->row;
    }

    public function deploymentEnabled(): bool
    {
        return (bool) config('db_scanner.enabled', false);
    }

    /** Scans and tests may start only when both switches are on and nothing is paused or stopped. */
    public function scanningAllowed(bool $fresh = true): bool
    {
        $row = $this->row($fresh);

        return $this->deploymentEnabled() && $row->enabled && ! $row->paused && ! $row->emergency_stop;
    }

    public function emergencyStopped(bool $fresh = true): bool
    {
        return (bool) $this->row($fresh)->emergency_stop;
    }

    public function statementTimeout(): int
    {
        return $this->bounded('statement_timeout_seconds', 'statement_timeout_seconds');
    }

    public function globalSlots(): int
    {
        return $this->bounded('global_slots', 'global_slots');
    }

    public function chunkRows(): int
    {
        return $this->bounded('chunk_rows', 'chunk_rows');
    }

    public function budgetSeconds(string $profile): int
    {
        $envelope = (array) config('db_scanner.envelope.budgets.'.$profile, ['min' => 10, 'max' => 30, 'default' => 30]);
        $stored = $this->row()->limits['budgets'][$profile] ?? null;

        return $this->clamp($stored ?? $envelope['default'], $envelope);
    }

    public function dailyMarketSeconds(): int
    {
        return $this->bounded('daily_market_seconds', 'daily_market_seconds');
    }

    public function envelope(string $key): mixed
    {
        return config('db_scanner.envelope.'.$key);
    }

    /**
     * Current limits plus their envelope, for the Schedules & limits view.
     */
    public function describe(): array
    {
        $env = (array) config('db_scanner.envelope');

        return [
            'statement_timeout_seconds' => ['value' => $this->statementTimeout()] + $env['statement_timeout_seconds'],
            'global_slots' => ['value' => $this->globalSlots()] + $env['global_slots'],
            'host_slots' => ['value' => 1, 'min' => 1, 'max' => 1, 'default' => 1, 'fixed' => true],
            'chunk_rows' => ['value' => $this->chunkRows()] + $env['chunk_rows'],
            'daily_market_seconds' => ['value' => $this->dailyMarketSeconds()] + $env['daily_market_seconds'],
            'budgets' => collect(['quick', 'standard', 'deep'])->mapWithKeys(fn ($p) => [
                $p => ['value' => $this->budgetSeconds($p)] + $env['budgets'][$p],
            ])->all(),
            'fixed' => [
                'chunk_bytes' => $env['chunk_bytes'],
                'value_bytes' => $env['value_bytes'],
                'slice_seconds' => $env['slice_seconds'],
                'lease_seconds' => $env['lease_seconds'],
                'connect_timeout_seconds' => $env['connect_timeout_seconds'],
                'run_deadline_hours' => $env['run_deadline_hours'],
                'sweep_days' => $env['sweep_days'],
            ],
        ];
    }

    /**
     * Validate requested limits strictly against the envelope (422 outside it).
     */
    public function validateLimits(array $input): array
    {
        $env = (array) config('db_scanner.envelope');
        $errors = [];
        $out = [];

        foreach (['statement_timeout_seconds', 'global_slots', 'chunk_rows', 'daily_market_seconds'] as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            if (! is_numeric($value) || (int) $value < $env[$key]['min'] || (int) $value > $env[$key]['max']) {
                $errors['limits.'.$key] = sprintf('Must be between %d and %d.', $env[$key]['min'], $env[$key]['max']);

                continue;
            }
            $out[$key] = (int) $value;
        }

        if (array_key_exists('host_slots', $input) && (int) $input['host_slots'] !== 1) {
            $errors['limits.host_slots'] = 'Per-host concurrency is fixed at 1.';
        }

        foreach ((array) ($input['budgets'] ?? []) as $profile => $value) {
            if (! isset($env['budgets'][$profile])) {
                $errors['limits.budgets.'.$profile] = 'Unknown profile.';

                continue;
            }
            $range = $env['budgets'][$profile];
            if (! is_numeric($value) || (int) $value < $range['min'] || (int) $value > $range['max']) {
                $errors['limits.budgets.'.$profile] = sprintf('Must be between %d and %d.', $range['min'], $range['max']);

                continue;
            }
            $out['budgets'][$profile] = (int) $value;
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    private function bounded(string $limitKey, string $envelopeKey): int
    {
        $envelope = (array) config('db_scanner.envelope.'.$envelopeKey);
        $stored = $this->row()->limits[$limitKey] ?? null;

        return $this->clamp($stored ?? $envelope['default'], $envelope);
    }

    private function clamp(mixed $value, array $envelope): int
    {
        $value = is_numeric($value) ? (int) $value : (int) $envelope['default'];

        return max((int) $envelope['min'], min((int) $envelope['max'], $value));
    }
}
