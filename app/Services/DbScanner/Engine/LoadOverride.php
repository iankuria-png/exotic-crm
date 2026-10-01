<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanMarketRun;
use App\Services\DbScanner\Evidence\EvidenceSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** A server-issued exception for one manual pass; never global or inherited. */
class LoadOverride
{
    public static function issue(int $platformId, int $actorId, string $mode, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['override_reason' => 'Enter a reason between 10 and 500 characters.']);
        }

        return [
            'platform_id' => $platformId,
            'actor_id' => $actorId,
            'mode' => $mode,
            'reason' => (new EvidenceSanitizer(500))->clean($reason),
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addSeconds($mode === 'preflight' ? 60 : 900)->toIso8601String(),
            'revoked_at' => null,
        ];
    }

    public static function ended(array $override): bool
    {
        try {
            return ! empty($override['revoked_at']) || empty($override['expires_at'])
                || CarbonImmutable::parse($override['expires_at'])->lessThanOrEqualTo(now());
        } catch (\Throwable) {
            return true;
        }
    }

    public static function forRun(DbScanMarketRun $run): ?array
    {
        // Always fetch: a running worker must see revocation from another request.
        $pass = $run->pass()->first();
        $override = $pass?->scope['load_override'] ?? null;
        if ($override === null) {
            return null;
        }
        if ($pass->trigger !== 'manual' || $pass->schedule_id !== null
            || count($pass->scope['platform_ids'] ?? []) !== 1
            || (int) ($override['actor_id'] ?? 0) !== (int) $pass->triggered_by
            || (int) ($override['platform_id'] ?? 0) !== (int) $run->platform_id
            || ($override['mode'] ?? null) !== $run->mode) {
            return ['expires_at' => null]; // Invalid stored scope fails closed.
        }

        return $override;
    }
}
