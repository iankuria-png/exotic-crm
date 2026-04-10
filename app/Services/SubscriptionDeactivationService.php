<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Support\DeactivationRequest;
use App\Support\DealDeactivationReason;
use App\Support\LinkedPaymentAction;
use InvalidArgumentException;

class SubscriptionDeactivationService
{
    public function deactivateDeal(Deal $deal, DeactivationRequest $request, ?int $actorId = null): Deal
    {
        $deal->loadMissing(['client.platform', 'product', 'platform', 'payment']);

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

        $linkedPayment = $this->applyLinkedPaymentAction($deal, $request, $actorId);

        $deal->forceFill([
            'status' => 'cancelled',
            'cancellation_reason_code' => $request->reasonCode->value,
            'cancellation_notes' => $request->reasonNotes,
            'cancelled_payment_id' => $linkedPayment?->id,
        ])->save();

        $syncService = new ClientSyncService($platform);
        $syncedClient = $syncService->syncOne($wpPostId);
        $this->applyClientRiskState($syncedClient, $request, $actorId, (int) $deal->id);
        $deal->setRelation('client', $syncedClient);

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'profile_deactivated',
            'actor_id' => $actorId,
            'content' => [
                'deal_id' => (int) $deal->id,
                'reason' => $request->auditReason(),
                'reason_code' => $request->reasonCode->value,
                'reason_notes' => $request->reasonNotes,
                'linked_payment_action' => $request->resolvedLinkedPaymentAction()->value,
                'cancelled_payment_id' => $linkedPayment?->id,
            ],
            'created_at' => now(),
        ]);

        return $deal->fresh(['client', 'product', 'platform']);
    }

    private function applyLinkedPaymentAction(Deal $deal, DeactivationRequest $request, ?int $actorId = null): ?Payment
    {
        $action = $request->resolvedLinkedPaymentAction();
        $payment = $this->resolveLinkedPayment($deal);

        if ($action === LinkedPaymentAction::NONE) {
            return null;
        }

        if (!$payment) {
            throw new InvalidArgumentException('This subscription does not have a linked payment to update.');
        }

        $meta = [
            'deal_id' => (int) $deal->id,
            'reason_code' => $request->reasonCode->value,
            'reason_notes' => $request->reasonNotes,
            'linked_payment_action' => $action->value,
            'actor_id' => $actorId,
            'applied_at' => now()->toDateTimeString(),
            'previous_status' => (string) $payment->status,
            'previous_resolution_code' => $payment->resolution_code,
        ];

        if ($action === LinkedPaymentAction::REVERSE) {
            $payment->forceFill([
                'resolution_code' => Payment::RESOLUTION_REVERSED,
                'resolution_meta_json' => $meta,
            ])->save();

            return $payment;
        }

        if ($action === LinkedPaymentAction::INVALIDATE) {
            $payment->forceFill([
                'status' => 'failed',
                'resolution_code' => Payment::RESOLUTION_INVALID_REFERENCE,
                'resolution_meta_json' => $meta,
                'failure_reason' => $request->reasonNotes ?: 'Invalid payment reference.',
            ])->save();
        }

        return $payment;
    }

    private function resolveLinkedPayment(Deal $deal): ?Payment
    {
        if ($deal->payment) {
            return $deal->payment;
        }

        if ($deal->payment_id) {
            return Payment::query()->find((int) $deal->payment_id);
        }

        return Payment::query()
            ->where('deal_id', (int) $deal->id)
            ->latest('id')
            ->first();
    }

    private function applyClientRiskState($client, DeactivationRequest $request, ?int $actorId, int $dealId): void
    {
        if (!$request->shouldFlagClientHighRisk()) {
            return;
        }

        $content = [
            'deal_id' => $dealId,
            'reason_code' => $request->reasonCode->value,
            'reason_notes' => $request->reasonNotes,
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
