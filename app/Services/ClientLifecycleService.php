<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Support\ClientLifecycleState;
use InvalidArgumentException;

/**
 * Orchestrates the Archived tier of the profile lifecycle (and un-archiving back
 * to Expired). Natural/manual expiry is handled by ExpiredSubscriptionReconciler;
 * a return to Active happens through subscription provisioning (which clears the
 * WordPress crm_lifecycle_state meta on activate). Removal reuses
 * ClientDeletionService via the existing destroy endpoint.
 *
 * Like the reconciler, this pushes the authoritative state to WordPress, re-mirrors
 * the profile, and records a timeline event. Audit logging is left to the caller
 * (mirrors ClientController::expireNow) so the service stays Request-free.
 */
class ClientLifecycleService
{
    /**
     * Move an Expired profile to Archived: still published & indexed, but excluded
     * from city/category listings. Idempotent when already archived.
     */
    public function archive(Client $client, ?int $actorId, string $trigger = 'manual'): Client
    {
        $client->refresh()->loadMissing('platform');

        if ($client->lifecycle_state === ClientLifecycleState::ARCHIVED) {
            return $client;
        }

        if ($client->lifecycle_state !== ClientLifecycleState::EXPIRED) {
            throw new InvalidArgumentException('Only Expired profiles can be archived.');
        }

        return $this->transition(
            $client,
            ClientLifecycleState::ARCHIVED,
            ['lifecycle_archived_at' => now()],
            'profile_archived',
            ['trigger' => $trigger],
            $actorId,
        );
    }

    /**
     * Restore an Archived profile to Expired (back into listings) without granting
     * contact access. Idempotent when already expired.
     */
    public function unarchive(Client $client, ?int $actorId): Client
    {
        $client->refresh()->loadMissing('platform');

        if ($client->lifecycle_state === ClientLifecycleState::EXPIRED) {
            return $client;
        }

        if ($client->lifecycle_state !== ClientLifecycleState::ARCHIVED) {
            throw new InvalidArgumentException('Only Archived profiles can be un-archived.');
        }

        return $this->transition(
            $client,
            ClientLifecycleState::EXPIRED,
            ['lifecycle_archived_at' => null],
            'profile_unarchived',
            [],
            $actorId,
        );
    }

    /**
     * @param  array<string, mixed>  $columns  Extra client columns to set alongside lifecycle_state.
     * @param  array<string, mixed>  $eventContent  Extra timeline event content.
     */
    private function transition(
        Client $client,
        string $state,
        array $columns,
        string $eventType,
        array $eventContent,
        ?int $actorId,
    ): Client {
        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            throw new InvalidArgumentException('Client is not linked to a WordPress profile.');
        }

        $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);

        if (! $platform->lifecycleEnabled()) {
            throw new InvalidArgumentException('The profile lifecycle policy is not enabled for this market.');
        }

        // 1. Publish the authoritative state to WordPress (keeps the post published).
        WpSyncService::forPlatform((int) $client->platform_id)->setLifecycleState($wpPostId, $state);

        // 2. Re-mirror, then stamp the lifecycle columns authoritatively.
        $synced = (new ClientSyncService($platform))->syncOne($wpPostId);
        Client::withoutRetentionRefresh(function () use ($synced, $state, $columns): void {
            $synced->forceFill(array_merge(['lifecycle_state' => $state], $columns))->save();
        });

        // 3. Audit trail.
        TimelineEvent::create([
            'platform_id' => (int) $synced->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $synced->id,
            'event_type' => $eventType,
            'actor_id' => $actorId,
            'content' => array_merge(['lifecycle_state' => $state], $eventContent),
            'created_at' => now(),
        ]);

        return $synced->fresh(['platform']);
    }
}
