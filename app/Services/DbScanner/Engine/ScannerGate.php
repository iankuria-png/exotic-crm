<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanConnection;
use App\Models\Platform;
use App\Services\DbScanner\ScannerSettings;
use App\Services\Ops\LoadShedder;
use Carbon\CarbonImmutable;

/**
 * Scanner-only admission policy. Fails closed:
 *
 * - LoadShedder level >= 1 blocks scanning even while global enforcement is
 *   observe-only (other consumers are unaffected; this reads level directly).
 * - Missing/invalid ops state or a sample older than three minutes blocks.
 * - The market must be "healthy" with a health check within ten minutes.
 * - Credentials must have a preflight proof for the current config version.
 *
 * Returns null when admission is allowed, otherwise a reason code. Load and
 * health reasons are auto-resumable pause reasons.
 */
class ScannerGate
{
    public function __construct(
        private readonly ScannerSettings $settings,
        private readonly LoadShedder $shedder,
    ) {}

    public function check(Platform $platform, ?DbScanConnection $connection, string $mode = 'scan'): ?string
    {
        $row = $this->settings->row(true);
        if ($row->emergency_stop) {
            return 'emergency_stop';
        }

        if ($mode !== 'preflight') {
            if (! $this->settings->deploymentEnabled() || ! $row->enabled) {
                return 'scanner_off';
            }
            if ($row->paused) {
                return 'paused_global';
            }
        }

        if (! $connection || (! $connection->enabled && $mode !== 'preflight')) {
            return 'credentials';
        }
        if ($mode !== 'preflight' && ! $connection->preflightValid()) {
            return 'credentials';
        }

        if ($reason = $this->loadReason()) {
            return $reason;
        }

        return $this->healthReason($platform);
    }

    public function loadReason(): ?string
    {
        if (! config('db_scanner.gates.require_ops_state', true)) {
            return null;
        }

        $state = $this->shedder->state();
        $sampledAt = $state['sampled_at'] ?? $state['evaluated_at'] ?? null;
        if ($state === [] || $sampledAt === null || ! isset($state['level'])) {
            return 'ops_state_missing';
        }

        try {
            $age = CarbonImmutable::parse((string) $sampledAt)->diffInSeconds(now(), false);
        } catch (\Throwable) {
            return 'ops_state_missing';
        }
        if ($age > (int) config('db_scanner.gates.ops_state_max_age_seconds', 180)) {
            return 'ops_state_stale';
        }

        if ((int) $state['level'] > (int) config('db_scanner.gates.max_load_level', 0)) {
            return 'load';
        }

        return null;
    }

    public function healthReason(Platform $platform): ?string
    {
        if (! config('db_scanner.gates.require_market_health', true)) {
            return null;
        }

        $platform->refresh();
        if ($platform->health_status !== 'healthy') {
            return 'health';
        }
        $checked = $platform->health_checked_at;
        if (! $checked || $checked->lt(now()->subSeconds((int) config('db_scanner.gates.health_max_age_seconds', 600)))) {
            return 'health_stale';
        }

        return null;
    }

    public static function autoResumable(?string $reason): bool
    {
        return in_array($reason, ['load', 'ops_state_missing', 'ops_state_stale', 'health', 'health_stale', 'window'], true);
    }

    public static function describe(?string $reason): string
    {
        return match ($reason) {
            'emergency_stop' => 'The scanner emergency stop is on.',
            'scanner_off' => 'Scanning is turned off.',
            'paused_global' => 'All scanning is paused.',
            'credentials' => 'This market has no enabled scanner credentials with a passing preflight for the current configuration.',
            'ops_state_missing' => 'Platform load state is unavailable, so scanning waits (fail closed).',
            'ops_state_stale' => 'Platform load state is stale, so scanning waits (fail closed).',
            'load' => 'The platform is under load (degradation level 1 or higher).',
            'health' => 'The market is not healthy.',
            'health_stale' => 'The market health check is older than ten minutes.',
            'window' => 'Outside the schedule window.',
            default => 'Scanning is not allowed right now.',
        };
    }
}
