<?php

namespace App\Services\Kyc;

use App\Jobs\Kyc\PushKycStatusJob;
use App\Models\Client;
use App\Models\KycDocument;
use App\Models\KycSubject;
use App\Models\KycSubjectSite;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KycSubjectService
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly KycSettingsService $settingsService,
    ) {
    }

    public function resolveOrCreateForClient(Client $client): KycSubject
    {
        $subject = $client->kycSubject ?: KycSubject::query()->firstOrCreate(
            ['client_id' => (int) $client->id],
            [
                'status' => KycSubject::STATUS_UNVERIFIED,
                'grace_started_at' => now(),
            ]
        );

        $this->ensureSiteLink($subject, $client);

        return $subject->fresh(['client', 'sites']);
    }

    public function resolveByWpIdentity(int $platformId, int $wpUserId, int $wpPostId): ?KycSubject
    {
        $client = Client::query()
            ->where('platform_id', $platformId)
            ->where('wp_post_id', $wpPostId)
            ->where('wp_user_id', $wpUserId)
            ->first();

        return $client ? $this->resolveOrCreateForClient($client) : null;
    }

    public function ensureSiteLink(KycSubject $subject, Client $client): KycSubjectSite
    {
        return KycSubjectSite::query()->updateOrCreate(
            [
                'subject_id' => (int) $subject->id,
                'platform_id' => (int) $client->platform_id,
                'wp_post_id' => (int) $client->wp_post_id,
            ],
            [
                'wp_user_id' => (int) ($client->wp_user_id ?? 0) ?: null,
            ]
        );
    }

    public function afterDocumentUploaded(KycSubject $subject): KycSubject
    {
        $requiredKinds = $this->settingsService->requiredDocumentKinds();
        $existingKinds = $subject->documents()->pluck('kind')->unique()->values()->all();
        $missing = array_diff($requiredKinds, $existingKinds);

        if ($missing === [] && $subject->status !== KycSubject::STATUS_APPROVED) {
            $subject->forceFill([
                'status' => KycSubject::STATUS_IN_REVIEW,
                'last_reason_user' => null,
                'last_reason_internal' => null,
            ])->save();
        } elseif ($subject->status === KycSubject::STATUS_INFO_REQUESTED) {
            $subject->forceFill(['status' => KycSubject::STATUS_IN_REVIEW])->save();
        }

        return $subject->fresh(['client', 'sites', 'documents']);
    }

    public function markApprovedFromSource(KycSubject $subject, string $source, ?User $actor = null, ?string $reason = null): KycSubject
    {
        $idempotencyKey = $this->buildIdempotencyKey($subject, 'approve_' . $source, $actor?->id);
        if (!$this->claimIdempotency($idempotencyKey, $subject, 'approve_' . $source)) {
            return $subject->fresh(['client', 'sites']);
        }

        return DB::transaction(function () use ($subject, $source, $actor, $reason) {
            $client = $subject->client()->firstOrFail();
            $beforeSubject = $subject->toArray();
            $beforeClient = $client->toArray();

            $subject->forceFill([
                'status' => KycSubject::STATUS_APPROVED,
                'verified_at' => now(),
                'expires_at' => now()->addDays(max(1, $this->settingsService->get()->reverify_interval_days ?: 365)),
                'last_reviewer_id' => $actor?->id,
                'last_reason_user' => $reason,
            ])->save();

            $client->forceFill([
                'verified' => true,
                'verified_source' => $source,
                'verified_source_at' => now(),
                'verified_source_actor_id' => $actor?->id,
                'verified_source_reason' => $reason,
            ])->save();

            $this->recordAuditAcrossSites($subject, $actor?->id, 'kyc.approved', $beforeSubject, $subject->fresh()->toArray(), $reason);
            $this->recordClientAudit($client, $actor?->id, $beforeClient, $client->fresh()->toArray(), $source === 'manual_crm_emergency' ? 'client.verified_emergency_set' : 'client.verified_status_update', $reason);
            app(KycStatusFanoutService::class)->dispatchSubject($subject->fresh(['client', 'sites']));

            return $subject->fresh(['client', 'sites']);
        });
    }

    public function reject(KycSubject $subject, string $reasonUser, ?string $reasonInternal = null, ?User $actor = null): KycSubject
    {
        $idempotencyKey = $this->buildIdempotencyKey($subject, 'reject', $actor?->id);
        if (!$this->claimIdempotency($idempotencyKey, $subject, 'reject')) {
            return $subject->fresh(['client', 'sites']);
        }

        return DB::transaction(function () use ($subject, $reasonUser, $reasonInternal, $actor) {
            $client = $subject->client()->firstOrFail();
            $beforeSubject = $subject->toArray();
            $beforeClient = $client->toArray();

            $subject->forceFill([
                'status' => KycSubject::STATUS_REJECTED,
                'last_reviewer_id' => $actor?->id,
                'last_reason_user' => $reasonUser,
                'last_reason_internal' => $reasonInternal,
            ])->save();

            $client->forceFill(['verified' => false])->save();

            $this->recordAuditAcrossSites($subject, $actor?->id, 'kyc.rejected', $beforeSubject, $subject->fresh()->toArray(), $reasonUser);
            $this->recordClientAudit($client, $actor?->id, $beforeClient, $client->fresh()->toArray(), 'client.verified_status_update', $reasonUser);
            app(KycStatusFanoutService::class)->dispatchSubject($subject->fresh(['client', 'sites']));

            return $subject->fresh(['client', 'sites']);
        });
    }

    public function requestInfo(KycSubject $subject, string $reasonUser, ?string $reasonInternal = null, ?User $actor = null): KycSubject
    {
        return DB::transaction(function () use ($subject, $reasonUser, $reasonInternal, $actor) {
            $before = $subject->toArray();
            $subject->forceFill([
                'status' => KycSubject::STATUS_INFO_REQUESTED,
                'last_reviewer_id' => $actor?->id,
                'last_reason_user' => $reasonUser,
                'last_reason_internal' => $reasonInternal,
                'last_info_request_at' => now(),
            ])->save();

            $this->recordAuditAcrossSites($subject, $actor?->id, 'kyc.requested_info', $before, $subject->fresh()->toArray(), $reasonUser);
            app(KycStatusFanoutService::class)->dispatchSubject($subject->fresh(['client', 'sites']));

            return $subject->fresh(['client', 'sites']);
        });
    }

    public function reRequest(KycSubject $subject, ?User $actor = null, ?string $reason = null): KycSubject
    {
        return DB::transaction(function () use ($subject, $actor, $reason) {
            $before = $subject->toArray();
            $subject->forceFill([
                'status' => KycSubject::STATUS_EXPIRED,
                'verified_at' => null,
                'last_reviewer_id' => $actor?->id,
                'last_reason_user' => $reason,
            ])->save();

            $subject->client()->update([
                'verified' => false,
            ]);

            $this->recordAuditAcrossSites($subject, $actor?->id, 'kyc.re_requested', $before, $subject->fresh()->toArray(), $reason);
            app(KycStatusFanoutService::class)->dispatchSubject($subject->fresh(['client', 'sites']));

            return $subject->fresh(['client', 'sites']);
        });
    }

    public function buildStatusPayload(KycSubject $subject): array
    {
        $client = $subject->client;

        return [
            'subject_id' => (int) $subject->id,
            'status' => (string) $subject->status,
            'verified_at' => optional($subject->verified_at)->toIso8601String(),
            'expires_at' => optional($subject->expires_at)->toIso8601String(),
            'last_reason_user' => $subject->last_reason_user,
            'grace_started_at' => optional($subject->grace_started_at)->toIso8601String(),
            'is_exempt' => $client ? !$client->kyc_required : false,
            'verified_source' => $client?->verified_source,
            'client_verified' => (bool) ($client?->verified ?? false),
        ];
    }

    private function recordAuditAcrossSites(KycSubject $subject, ?int $actorId, string $action, array $before, array $after, ?string $reason = null): void
    {
        $sites = $subject->sites()->get();
        if ($sites->isEmpty()) {
            $subject->loadMissing('client');
            $platformId = (int) ($subject->client?->platform_id ?? 0);
            if ($platformId > 0) {
                $this->auditService->record([
                    'platform_id' => $platformId,
                    'actor_id' => $actorId,
                    'action' => $action,
                    'entity_type' => 'kyc_subject',
                    'entity_id' => (int) $subject->id,
                    'before_state' => $before,
                    'after_state' => $after,
                    'reason' => $reason,
                ]);
            }
            return;
        }

        foreach ($sites as $site) {
            $this->auditService->record([
                'platform_id' => (int) $site->platform_id,
                'actor_id' => $actorId,
                'action' => $action,
                'entity_type' => 'kyc_subject',
                'entity_id' => (int) $subject->id,
                'before_state' => $before,
                'after_state' => $after,
                'reason' => $reason,
            ]);
        }
    }

    private function recordClientAudit(Client $client, ?int $actorId, array $before, array $after, string $action, ?string $reason = null): void
    {
        $this->auditService->record([
            'platform_id' => (int) $client->platform_id,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'before_state' => ['verified' => (bool) ($before['verified'] ?? false), 'verified_source' => $before['verified_source'] ?? null],
            'after_state' => ['verified' => (bool) ($after['verified'] ?? false), 'verified_source' => $after['verified_source'] ?? null],
            'reason' => $reason,
        ]);
    }

    private function claimIdempotency(string $key, KycSubject $subject, string $action): bool
    {
        try {
            DB::table('kyc_idempotency_keys')->insert([
                'key' => $key,
                'subject_id' => (int) $subject->id,
                'action' => $action,
                'created_at' => now(),
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function buildIdempotencyKey(KycSubject $subject, string $action, ?int $actorId = null): string
    {
        return hash('sha256', implode('|', [(int) $subject->id, $action, (int) ($actorId ?? 0), now()->format('Y-m-d-H-i-s')]));
    }
}
