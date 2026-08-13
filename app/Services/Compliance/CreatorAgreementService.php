<?php

namespace App\Services\Compliance;

use App\Models\Client;
use App\Models\CreatorAgreementAcceptance;
use App\Models\CreatorAgreementVersion;
use App\Models\Platform;
use App\Services\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CreatorAgreementService
{
    private const SOURCE_CONTEXTS = [
        'independent_signup',
        'agency_signup',
        'agency_added_profile',
        'profile_edit',
        'fast_signup',
        'admin_assisted',
    ];

    public function __construct(private readonly AuditService $auditService) {}

    public function currentAgreement(): ?CreatorAgreementVersion
    {
        return CreatorAgreementVersion::query()
            ->whereNotNull('published_at')
            ->whereNull('retired_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
    }

    public function recordAcceptance(array $payload, Platform $platform): CreatorAgreementAcceptance
    {
        $this->guardAcceptancePayload($payload);

        $idempotencyKey = trim((string) $payload['idempotency_key']);
        $existing = CreatorAgreementAcceptance::query()
            ->where('platform_id', (int) $platform->id)
            ->where('wp_idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing->fresh(['version', 'client']);
        }

        return DB::transaction(function () use ($payload, $platform, $idempotencyKey): CreatorAgreementAcceptance {
            $version = $this->resolveAgreementVersion($payload);
            if ($version->retired_at !== null) {
                throw new InvalidArgumentException('Creator agreement version is retired.');
            }

            $client = $this->resolveClient($platform, $payload);

            $acceptance = CreatorAgreementAcceptance::query()->create([
                'agreement_version_id' => (int) $version->id,
                'client_id' => $client?->id,
                'platform_id' => (int) $platform->id,
                'wp_user_id' => $this->nullableInt($payload['wp_user_id'] ?? null),
                'wp_post_id' => $this->nullableInt($payload['wp_post_id'] ?? null),
                'actor_wp_user_id' => $this->nullableInt($payload['actor_wp_user_id'] ?? null),
                'source_context' => (string) $payload['source_context'],
                'accepted_at' => now(),
                'ip_address' => $this->nullableString($payload['ip_address'] ?? null, 120),
                'user_agent' => $this->nullableString($payload['user_agent'] ?? null, 2000),
                'wp_idempotency_key' => $idempotencyKey,
                'raw_payload' => $payload,
            ]);

            $this->auditService->record([
                'platform_id' => (int) $platform->id,
                'action' => 'creator_agreement.accepted',
                'entity_type' => 'creator_agreement_acceptance',
                'entity_id' => (int) $acceptance->id,
                'after_state' => $acceptance->fresh(['version', 'client'])->toArray(),
                'reason' => 'Creator agreement accepted from WordPress',
                'ip_address' => $acceptance->ip_address,
            ]);

            return $acceptance->fresh(['version', 'client']);
        });
    }

    public function payloadForClient(Client $client): array
    {
        $latest = CreatorAgreementAcceptance::query()
            ->with('version')
            ->where(function ($query) use ($client): void {
                $query->where('client_id', (int) $client->id)
                    ->orWhere(function ($fallback) use ($client): void {
                        $fallback->where('platform_id', (int) $client->platform_id)
                            ->where('wp_post_id', (int) $client->wp_post_id);
                    });
            })
            ->orderByDesc('accepted_at')
            ->first();

        if (! $latest) {
            return [
                'status' => 'missing',
                'latest' => null,
            ];
        }

        return [
            'status' => $latest->version?->retired_at ? 'retired_version' : 'accepted',
            'latest' => $this->serializeAcceptance($latest),
        ];
    }

    public function serializeAcceptance(CreatorAgreementAcceptance $acceptance): array
    {
        return [
            'id' => (int) $acceptance->id,
            'version_key' => $acceptance->version?->version_key,
            'title' => $acceptance->version?->title,
            'body_sha256' => $acceptance->version?->body_sha256,
            'source_url' => $acceptance->version?->source_url,
            'source_context' => $acceptance->source_context,
            'wp_user_id' => $acceptance->wp_user_id ? (int) $acceptance->wp_user_id : null,
            'wp_post_id' => $acceptance->wp_post_id ? (int) $acceptance->wp_post_id : null,
            'actor_wp_user_id' => $acceptance->actor_wp_user_id ? (int) $acceptance->actor_wp_user_id : null,
            'accepted_at' => optional($acceptance->accepted_at)->toIso8601String(),
            'ip_address' => $acceptance->ip_address,
            'user_agent' => $acceptance->user_agent,
        ];
    }

    private function guardAcceptancePayload(array $payload): void
    {
        if (($payload['accepted'] ?? null) !== true) {
            throw new InvalidArgumentException('Creator agreement must be affirmatively accepted.');
        }

        foreach (['agreement_version_key', 'source_context', 'idempotency_key'] as $field) {
            if (trim((string) ($payload[$field] ?? '')) === '') {
                throw new InvalidArgumentException("Missing required field: {$field}.");
            }
        }

        if (! in_array((string) $payload['source_context'], self::SOURCE_CONTEXTS, true)) {
            throw new InvalidArgumentException('Invalid creator agreement source context.');
        }
    }

    private function resolveAgreementVersion(array $payload): CreatorAgreementVersion
    {
        $versionKey = trim((string) $payload['agreement_version_key']);
        $bodyHtml = (string) ($payload['agreement_body_html'] ?? '');
        $bodySha = strtolower(trim((string) ($payload['agreement_body_sha256'] ?? '')));
        if ($bodySha === '') {
            $bodySha = hash('sha256', $bodyHtml !== '' ? $bodyHtml : $versionKey);
        }

        if (! preg_match('/^[a-f0-9]{64}$/', $bodySha)) {
            throw new InvalidArgumentException('Invalid creator agreement body hash.');
        }

        return CreatorAgreementVersion::query()->firstOrCreate(
            ['version_key' => $versionKey],
            [
                'title' => trim((string) ($payload['agreement_title'] ?? 'Creator Agreement')) ?: 'Creator Agreement',
                'body_html' => $bodyHtml !== '' ? $bodyHtml : null,
                'body_sha256' => $bodySha,
                'source_url' => $this->nullableString($payload['agreement_source_url'] ?? null, 500),
                'published_at' => now(),
            ]
        );
    }

    private function resolveClient(Platform $platform, array $payload): ?Client
    {
        $postId = $this->nullableInt($payload['wp_post_id'] ?? null);
        if ($postId) {
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
