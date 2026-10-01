<?php

namespace App\Services\DbScanner;

use App\Models\DbScanAuditEvent;
use App\Services\DbScanner\Evidence\EvidenceSanitizer;

/**
 * Transactional scanner governance audit.
 *
 * Unlike AuditService (best-effort, market-scoped), this writer throws on
 * failure so the surrounding mutation transaction rolls back: a scanner
 * configuration change without its audit record must not happen. Secrets
 * are stripped and free text is redacted before storage.
 */
class DbScanAuditWriter
{
    private const SECRET_KEYS = ['password', 'username', 'tls_ca', 'secret', 'token'];

    public function __construct(private readonly EvidenceSanitizer $sanitizer = new EvidenceSanitizer(500)) {}

    public function record(
        ?int $actorId,
        string $entity,
        string|int|null $entityId,
        string $action,
        ?array $before = null,
        ?array $after = null,
        ?int $platformId = null,
    ): DbScanAuditEvent {
        return DbScanAuditEvent::query()->create([
            'actor_id' => $actorId,
            'actor_type' => $actorId ? 'user' : 'system',
            'scope_key' => $platformId ? 'platform:'.$platformId : 'network',
            'platform_id' => $platformId,
            'entity' => mb_substr($entity, 0, 40),
            'entity_id' => $entityId === null ? null : mb_substr((string) $entityId, 0, 60),
            'action' => mb_substr($action, 0, 40),
            'before' => $before === null ? null : $this->clean($before),
            'after' => $after === null ? null : $this->clean($after),
            'created_at' => now(),
        ]);
    }

    private function clean(array $data, int $depth = 0): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSecretKey($key)) {
                $out[$key] = $value === null || $value === '' ? null : '[set]';

                continue;
            }
            if (is_array($value)) {
                $out[$key] = $depth > 4 ? '[nested]' : $this->clean($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = $this->sanitizer->clean($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private function isSecretKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SECRET_KEYS as $secret) {
            if (str_contains($key, $secret)) {
                return true;
            }
        }

        return false;
    }
}
