<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Platform;
use App\Models\TimelineEvent;
use InvalidArgumentException;

class SubscriptionDeactivationService
{
    public function deactivateDeal(Deal $deal, string $reason, ?int $actorId = null): Deal
    {
        $deal->loadMissing(['client.platform', 'product', 'platform']);

        $client = $deal->client;
        if (!$client) {
            throw new InvalidArgumentException('Deal has no associated client.');
        }

        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            throw new InvalidArgumentException('Client is not linked to a WordPress profile.');
        }

        $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);
        $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
        $wpSync->deactivateClient($wpPostId);

        $deal->forceFill([
            'status' => 'cancelled',
        ])->save();

        $syncService = new ClientSyncService($platform);
        $syncedClient = $syncService->syncOne($wpPostId);
        $deal->setRelation('client', $syncedClient);

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'profile_deactivated',
            'actor_id' => $actorId,
            'content' => [
                'deal_id' => (int) $deal->id,
                'reason' => $reason,
            ],
            'created_at' => now(),
        ]);

        return $deal->fresh(['client', 'product', 'platform']);
    }
}
