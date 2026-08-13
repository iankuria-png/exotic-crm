<?php

namespace App\Services\Compliance;

use App\Models\Client;
use App\Models\ContentComplianceDeclaration;
use App\Models\Platform;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContentDeclarationService
{
    private const CONTENT_KINDS = [
        'profile_photo',
        'profile_video',
        'verified_status',
        'agency_logo',
        'classified_photo',
    ];

    private const PARTICIPANT_STATUSES = [
        ContentComplianceDeclaration::PARTICIPANT_SOLO,
        ContentComplianceDeclaration::PARTICIPANT_OTHER_PEOPLE,
        ContentComplianceDeclaration::PARTICIPANT_UNKNOWN_LEGACY,
    ];

    public function __construct(private readonly AuditService $auditService) {}

    public function recordFromWp(array $payload, Platform $platform): ContentComplianceDeclaration
    {
        $this->guardPayload($payload);

        $idempotencyKey = trim((string) $payload['idempotency_key']);
        $existing = ContentComplianceDeclaration::query()
            ->where('platform_id', (int) $platform->id)
            ->where('wp_idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing->fresh(['client', 'releaseParticipants']);
        }

        return DB::transaction(function () use ($payload, $platform, $idempotencyKey): ContentComplianceDeclaration {
            $participantStatus = (string) $payload['participant_status'];
            $status = $participantStatus === ContentComplianceDeclaration::PARTICIPANT_SOLO
                ? ContentComplianceDeclaration::STATUS_ACCEPTED
                : ContentComplianceDeclaration::STATUS_BLOCKED_PENDING_RELEASE;

            $client = $this->resolveClient($platform, $payload);
            $declaration = ContentComplianceDeclaration::query()->create([
                'client_id' => $client?->id,
                'platform_id' => (int) $platform->id,
                'wp_user_id' => $this->nullableInt($payload['wp_user_id'] ?? null),
                'wp_post_id' => (int) $payload['wp_post_id'],
                'wp_attachment_id' => $this->nullableInt($payload['wp_attachment_id'] ?? null),
                'content_kind' => (string) $payload['content_kind'],
                'participant_status' => $participantStatus,
                'status' => $status,
                'declared_at' => now(),
                'ip_address' => $this->nullableString($payload['ip_address'] ?? null, 120),
                'user_agent' => $this->nullableString($payload['user_agent'] ?? null, 2000),
                'wp_idempotency_key' => $idempotencyKey,
                'raw_payload' => $payload,
            ]);

            $this->auditService->record([
                'platform_id' => (int) $platform->id,
                'action' => 'content_compliance.declared',
                'entity_type' => 'content_compliance_declaration',
                'entity_id' => (int) $declaration->id,
                'after_state' => $declaration->fresh(['client'])->toArray(),
                'reason' => 'Content compliance declaration received from WordPress',
                'ip_address' => $declaration->ip_address,
            ]);

            return $declaration->fresh(['client', 'releaseParticipants']);
        });
    }

    public function payloadForClient(Client $client): array
    {
        $declarations = ContentComplianceDeclaration::query()
            ->with('releaseParticipants')
            ->where(function ($query) use ($client): void {
                $query->where('client_id', (int) $client->id)
                    ->orWhere(function ($fallback) use ($client): void {
                        $fallback->where('platform_id', (int) $client->platform_id)
                            ->where('wp_post_id', (int) $client->wp_post_id);
                    });
            })
            ->orderByDesc('declared_at')
            ->limit(100)
            ->get();

        $pending = $declarations
            ->where('status', ContentComplianceDeclaration::STATUS_BLOCKED_PENDING_RELEASE)
            ->count();

        return [
            'status' => $pending > 0 ? 'release_required' : ($declarations->isEmpty() ? 'missing' : 'ok'),
            'pending_release_count' => $pending,
            'items' => $declarations->map(fn (ContentComplianceDeclaration $declaration) => $this->serialize($declaration))->values(),
        ];
    }

    public function serialize(ContentComplianceDeclaration $declaration): array
    {
        return [
            'id' => (int) $declaration->id,
            'client_id' => $declaration->client_id ? (int) $declaration->client_id : null,
            'platform_id' => (int) $declaration->platform_id,
            'wp_user_id' => $declaration->wp_user_id ? (int) $declaration->wp_user_id : null,
            'wp_post_id' => (int) $declaration->wp_post_id,
            'wp_attachment_id' => $declaration->wp_attachment_id ? (int) $declaration->wp_attachment_id : null,
            'content_kind' => $declaration->content_kind,
            'participant_status' => $declaration->participant_status,
            'status' => $declaration->status,
            'declared_at' => optional($declaration->declared_at)->toIso8601String(),
            'ip_address' => $declaration->ip_address,
            'release_participants' => $declaration->releaseParticipants->map(fn ($participant) => [
                'id' => (int) $participant->id,
                'display_name' => $participant->display_name,
                'release_status' => $participant->release_status,
                'reviewed_at' => optional($participant->reviewed_at)->toIso8601String(),
            ])->values(),
        ];
    }

    private function guardPayload(array $payload): void
    {
        foreach (['wp_post_id', 'content_kind', 'participant_status', 'idempotency_key'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                throw new InvalidArgumentException("Missing required field: {$field}.");
            }
        }

        if ((int) $payload['wp_post_id'] <= 0) {
            throw new InvalidArgumentException('Invalid WordPress post id.');
        }

        if (! in_array((string) $payload['content_kind'], self::CONTENT_KINDS, true)) {
            throw new InvalidArgumentException('Invalid content kind.');
        }

        if (! in_array((string) $payload['participant_status'], self::PARTICIPANT_STATUSES, true)) {
            throw new InvalidArgumentException('Invalid participant declaration.');
        }
    }

    private function resolveClient(Platform $platform, array $payload): ?Client
    {
        $postId = (int) ($payload['wp_post_id'] ?? 0);
        if ($postId > 0) {
            $client = Client::query()
                ->where('platform_id', (int) $platform->id)
                ->where('wp_post_id', $postId)
                ->first();

            if ($client) {
                return $client;
            }
        }

        $userId = $this->nullableInt($payload['wp_user_id'] ?? null);
        if ($userId) {
            return Client::query()
                ->where('platform_id', (int) $platform->id)
                ->where('wp_user_id', $userId)
                ->first();
        }

        return null;
    }

    private function nullableInt($value): ?int
    {
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function nullableString($value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
