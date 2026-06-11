<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Support\CrmClientChurnReason;
use App\Support\DeactivationRequest;
use InvalidArgumentException;

class ClientSubscriptionDeactivationService
{
    public function deactivate(Client $client, DeactivationRequest $request, ?int $actorId = null): Client
    {
        $client->loadMissing(['platform', 'activeDeal']);

        $wpPostId = (int) ($client->wp_post_id ?? 0);
        if ($wpPostId <= 0) {
            throw new InvalidArgumentException('Client is not linked to a WordPress profile.');
        }

        if ($client->activeDeal()->exists()) {
            throw new InvalidArgumentException('This client has an active CRM subscription. Deactivate the deal instead.');
        }

        $platform = $client->platform ?? Platform::findOrFail((int) $client->platform_id);
        $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
        $wpSync->deactivateClient($wpPostId);

        $syncService = new ClientSyncService($platform);
        $syncedClient = $syncService->syncOne($wpPostId);
        app(ClientChurnStamper::class)->stamp(
            $syncedClient,
            CrmClientChurnReason::ADMIN_DEACTIVATED,
            'profile_inactive',
            now(),
        );
        $this->applyClientRiskState($syncedClient, $request, $actorId);

        TimelineEvent::create([
            'platform_id' => (int) $syncedClient->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $syncedClient->id,
            'event_type' => 'profile_deactivated',
            'actor_id' => $actorId,
            'content' => [
                'deal_id' => null,
                'reason' => $request->auditReason(),
                'reason_code' => $request->reasonCode->value,
                'reason_notes' => $request->reasonNotes,
                'deactivation_scope' => 'client_wp_subscription',
            ],
            'created_at' => now(),
        ]);

        return $syncedClient->fresh();
    }

    private function applyClientRiskState(Client $client, DeactivationRequest $request, ?int $actorId): void
    {
        if (! $request->shouldFlagClientHighRisk()) {
            return;
        }

        $content = [
            'deal_id' => null,
            'reason_code' => $request->reasonCode->value,
            'reason_notes' => $request->reasonNotes,
            'deactivation_scope' => 'client_wp_subscription',
        ];

        if ((bool) $client->is_high_risk) {
            TimelineEvent::create([
                'platform_id' => (int) $client->platform_id,
                'entity_type' => 'client',
                'entity_id' => (int) $client->id,
                'event_type' => 'client_risk_reaffirmed',
                'actor_id' => $actorId,
                'content' => array_merge($content, [
                    'original_risk_reason_code' => $client->risk_reason_code,
                    'original_risk_marked_at' => optional($client->risk_marked_at)->toDateTimeString(),
                    'original_risk_marked_by' => $client->risk_marked_by,
                ]),
                'created_at' => now(),
            ]);

            return;
        }

        $client->forceFill([
            'is_high_risk' => true,
            'risk_reason_code' => $request->reasonCode->value,
            'risk_marked_at' => now(),
            'risk_marked_by' => $actorId,
        ])->save();

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'client_risk_marked',
            'actor_id' => $actorId,
            'content' => $content,
            'created_at' => now(),
        ]);
    }
}
