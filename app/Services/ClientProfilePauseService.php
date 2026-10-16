<?php

namespace App\Services;

use App\Exceptions\ClientLifecycleMutationException;
use App\Models\Client;
use App\Models\Platform;
use App\Models\TimelineEvent;
use Illuminate\Http\Client\RequestException;

/**
 * Hide a public profile at the companion's request, and bring it back.
 *
 * WordPress does what its own admin "set to private" toggle does (private +
 * notactive) and leaves the paid plan alone, so the subscription clock keeps
 * running. The CRM records who paused it so a pause can be told apart from a
 * new profile awaiting admin activation, which WordPress also marks notactive.
 */
class ClientProfilePauseService
{
    public function __construct(
        private readonly ClientLifecycleMutationLock $lifecycleLock,
    ) {}

    public function pause(Client $client, int $actorId, ?string $reason = null): Client
    {
        return $this->lifecycleLock->run(
            $client,
            fn () => $this->pauseLocked($client, $actorId, $reason),
        );
    }

    public function resume(Client $client, int $actorId): Client
    {
        return $this->lifecycleLock->run(
            $client,
            fn () => $this->resumeLocked($client, $actorId),
        );
    }

    private function pauseLocked(Client $client, int $actorId, ?string $reason): Client
    {
        $client->refresh()->loadMissing('platform');
        $wpPostId = $this->wpPostId($client);

        if ($client->isProfilePaused()) {
            return $client;
        }

        if ((string) $client->profile_status !== 'publish') {
            throw new ClientLifecycleMutationException('Only a public profile can be paused.', 'not_public');
        }

        $result = $this->setVisibility($client, $wpPostId, 'pause');

        $synced = $this->resync($client, $wpPostId);
        $synced->forceFill([
            'profile_paused_at' => now(),
            'profile_paused_by' => $actorId,
        ])->save();

        $this->recordEvent($synced, 'profile_paused', $actorId, array_filter([
            'reason' => $reason !== null && trim($reason) !== '' ? trim($reason) : null,
            'post_status' => $result['post_status'] ?? null,
        ], static fn ($value) => $value !== null));

        return $synced->fresh(['platform']);
    }

    private function resumeLocked(Client $client, int $actorId): Client
    {
        $client->refresh()->loadMissing('platform');
        $wpPostId = $this->wpPostId($client);

        if ($client->profile_removal_requested_at !== null) {
            throw new ClientLifecycleMutationException(
                'This profile has a removal request and cannot be republished.',
                'profile_removal_requested'
            );
        }

        if ($client->profile_paused_at === null) {
            throw new ClientLifecycleMutationException('This profile was not paused from the CRM.', 'not_paused');
        }

        $result = $this->setVisibility($client, $wpPostId, 'resume');

        $synced = $this->resync($client, $wpPostId);
        $synced->forceFill([
            'profile_paused_at' => null,
            'profile_paused_by' => null,
        ])->save();

        $this->recordEvent($synced, 'profile_resumed', $actorId, array_filter([
            'post_status' => $result['post_status'] ?? null,
            'needs_payment' => (bool) ($result['needs_payment'] ?? false) ?: null,
        ], static fn ($value) => $value !== null));

        return $synced->fresh(['platform']);
    }

    private function wpPostId(Client $client): int
    {
        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            throw new ClientLifecycleMutationException('Client is not linked to a WordPress profile.', 'not_linked');
        }

        return $wpPostId;
    }

    private function setVisibility(Client $client, int $wpPostId, string $action): array
    {
        try {
            return WpSyncService::forPlatform((int) $client->platform_id)->setClientVisibility($wpPostId, $action);
        } catch (RequestException $e) {
            $status = $e->response->status();
            $code = (string) ($e->response->json('code') ?? '');

            if ($status === 404 && $code === 'rest_no_route') {
                throw new ClientLifecycleMutationException(
                    'This market needs the latest Exotic CRM Sync plugin before profiles can be paused.',
                    'plugin_update_required'
                );
            }

            if ($status === 409 && in_array($code, ['not_public', 'not_paused'], true)) {
                throw new ClientLifecycleMutationException(
                    (string) ($e->response->json('message') ?: 'WordPress refused the change.'),
                    $code
                );
            }

            throw $e;
        }
    }

    private function resync(Client $client, int $wpPostId): Client
    {
        $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);

        return (new ClientSyncService($platform))->syncOne($wpPostId);
    }

    private function recordEvent(Client $client, string $eventType, int $actorId, array $content): void
    {
        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'content' => $content,
            'created_at' => now(),
        ]);
    }
}
