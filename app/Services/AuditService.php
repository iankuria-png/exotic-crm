<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditService
{
    public function record(array $payload): ?AuditLog
    {
        try {
            $platformId = (int) ($payload['platform_id'] ?? 0);
            $entityId = (int) ($payload['entity_id'] ?? 0);
            $entityType = (string) ($payload['entity_type'] ?? '');
            $action = $this->normalizeAction((string) ($payload['action'] ?? ''));

            if ($platformId <= 0 || $entityId <= 0 || $entityType === '' || $action === '') {
                Log::warning('AuditService skipped invalid payload', ['payload' => $payload]);
                return null;
            }

            return AuditLog::create([
                'platform_id' => $platformId,
                'actor_id' => $this->resolveActorId($payload['actor_id'] ?? null),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'before_state' => $this->normalizeState($payload['before_state'] ?? null),
                'after_state' => $this->normalizeState($payload['after_state'] ?? null),
                'reason' => $this->normalizeReason($payload['reason'] ?? null),
                'ip_address' => $payload['ip_address'] ?? null,
                'created_at' => $payload['created_at'] ?? now(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('AuditService failed to write audit log', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return null;
        }
    }

    public function recordSystem(array $payload): ?AuditLog
    {
        try {
            $entityId = (int) ($payload['entity_id'] ?? 0);
            $entityType = (string) ($payload['entity_type'] ?? '');
            $action = $this->normalizeAction((string) ($payload['action'] ?? ''));

            if ($entityId <= 0 || $entityType === '' || $action === '' || !preg_match('/^(faq_|university_)/', $entityType)) {
                Log::warning('AuditService skipped invalid system payload', ['payload' => $payload]);
                return null;
            }

            return AuditLog::create([
                'platform_id' => null,
                'actor_id' => $this->resolveActorId($payload['actor_id'] ?? null),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'before_state' => $this->normalizeState($payload['before_state'] ?? null),
                'after_state' => $this->normalizeState($payload['after_state'] ?? null),
                'reason' => $this->normalizeReason($payload['reason'] ?? null),
                'ip_address' => $payload['ip_address'] ?? null,
                'created_at' => $payload['created_at'] ?? now(),
            ]);
        } catch (\Throwable $exception) {
            Log::error('AuditService failed to write system audit log', [
                'error' => $exception->getMessage(),
                'payload' => $payload,
            ]);

            return null;
        }
    }

    private function normalizeAction(string $action): string
    {
        $action = strtolower(trim($action));
        $action = preg_replace('/[^a-z0-9]+/', '_', $action) ?? '';

        return trim($action, '_');
    }

    private function normalizeState($state): ?array
    {
        if ($state === null) {
            return null;
        }

        if (is_array($state)) {
            return $state;
        }

        return [
            'value' => $state,
        ];
    }

    private function normalizeReason($reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $normalized = trim((string) $reason);

        return $normalized === '' ? null : $normalized;
    }

    public function fromRequest(
        Request $request,
        int $platformId,
        string $action,
        string $entityType,
        int $entityId,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?string $reason = null
    ): ?AuditLog {
        return $this->record([
            'platform_id' => $platformId,
            'actor_id' => optional($request->user())->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'reason' => $reason,
            'ip_address' => $request->ip(),
        ]);
    }

    public function fromSystemRequest(
        Request $request,
        string $action,
        string $entityType,
        int $entityId,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?string $reason = null
    ): ?AuditLog {
        return $this->recordSystem([
            'actor_id' => optional($request->user())->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'reason' => $reason,
            'ip_address' => $request->ip(),
        ]);
    }

    private function resolveActorId($actorId): int
    {
        if ($actorId) {
            return (int) $actorId;
        }

        $fallback = User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->value('id');

        if ($fallback) {
            return (int) $fallback;
        }

        $anyUser = User::query()->orderBy('id')->value('id');
        if ($anyUser) {
            return (int) $anyUser;
        }

        throw new \RuntimeException('No user exists to satisfy non-null audit actor_id constraint.');
    }
}
