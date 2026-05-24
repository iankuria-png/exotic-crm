<?php

namespace App\Services\Messaging;

use App\Models\TimelineEvent;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppProviderProfile;
use App\Models\WhatsAppRoutingRule;
use App\Services\AuditService;
use App\Services\Messaging\Engines\MetaCloudApiEngine;
use App\Support\CrmAuditAction;
use Illuminate\Support\Facades\Log;

class WhatsAppGatewayService
{
    /** @var array<string, WhatsAppEngineInterface> */
    private array $engines;

    public function __construct(
        MetaCloudApiEngine $metaCloudApiEngine,
        private readonly SuppressionService $suppressionService,
        private readonly AuditService $auditService,
    ) {
        $this->engines = [
            $metaCloudApiEngine->id() => $metaCloudApiEngine,
        ];
    }

    public function send(SendRequest $request): DispatchResult
    {
        $existing = $this->findExistingMessage($request);
        if ($existing) {
            return new DispatchResult(
                success: in_array($existing->status, ['sent', 'delivered', 'read'], true),
                channel: 'whatsapp',
                status: $existing->status,
                whatsAppMessage: $existing,
            );
        }

        $profile = $this->resolveProfile($request);
        if (!$profile) {
            $message = $this->createMessage($request, null, 'rejected', 'no_route', 'No enabled WhatsApp routing profile is configured.');

            return new DispatchResult(false, 'whatsapp', 'rejected', $message, errorCode: 'no_route', errorMessage: 'No enabled WhatsApp routing profile is configured.');
        }

        if ($profile->kill_switch_enabled) {
            $message = $this->createMessage($request, $profile, 'rejected', 'kill_switch_enabled', 'WhatsApp profile kill switch is enabled.');
            $this->recordFailureEvents($message, $request, 'WhatsApp profile kill switch is enabled.');

            return new DispatchResult(false, 'whatsapp', 'rejected', $message, errorCode: 'kill_switch_enabled', errorMessage: 'WhatsApp profile kill switch is enabled.');
        }

        if ($this->suppressionService->isSuppressed($request->recipient->phoneE164, 'whatsapp', $request->recipient->platformId)) {
            $message = $this->createMessage($request, $profile, 'suppressed', 'suppressed', 'Recipient has an active WhatsApp opt-out.');

            return new DispatchResult(false, 'whatsapp', 'suppressed', $message, errorCode: 'suppressed', errorMessage: 'Recipient has an active WhatsApp opt-out.');
        }

        $message = $this->createMessage($request, $profile, 'queued');
        $engine = $this->engines[$profile->engine] ?? null;

        if (!$engine) {
            $message->forceFill([
                'status' => 'failed',
                'error_code' => 'engine_unavailable',
                'error_message' => 'WhatsApp engine is not available.',
                'failed_at' => now(),
            ])->save();
            $this->recordFailureEvents($message, $request, 'WhatsApp engine is not available.');

            return new DispatchResult(false, 'whatsapp', 'failed', $message, errorCode: 'engine_unavailable', errorMessage: 'WhatsApp engine is not available.');
        }

        try {
            $result = $engine->send($request->withProfile($profile));
        } catch (\Throwable $exception) {
            Log::error('WhatsApp dispatch failed', [
                'profile_id' => $profile->id,
                'message_id' => $message->id,
                'error' => $exception->getMessage(),
            ]);

            $result = SendResult::failed('failed', 'exception', $exception->getMessage());
        }

        $message->forceFill([
            'status' => $result->status,
            'provider_message_id' => $result->providerMessageId,
            'error_code' => $result->errorCode,
            'error_message' => $result->errorMessage,
            'sent_at' => $result->success ? now() : null,
            'failed_at' => $result->success ? null : now(),
        ])->save();

        if ($result->success) {
            $this->recordSentEvents($message, $request);
        } else {
            $this->recordFailureEvents($message, $request, $result->errorMessage);
        }

        return new DispatchResult(
            success: $result->success,
            channel: 'whatsapp',
            status: $result->status,
            whatsAppMessage: $message->refresh(),
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
        );
    }

    private function findExistingMessage(SendRequest $request): ?WhatsAppMessage
    {
        if (!$request->idempotencyKey) {
            return null;
        }

        return WhatsAppMessage::query()
            ->where('direction', 'outbound')
            ->where('idempotency_key', $request->idempotencyKey)
            ->first();
    }

    private function resolveProfile(SendRequest $request): ?WhatsAppProviderProfile
    {
        $forcedProfileId = $request->context['provider_profile_id'] ?? null;
        if ($forcedProfileId) {
            return WhatsAppProviderProfile::query()
                ->whereKey((int) $forcedProfileId)
                ->where('active', true)
                ->first();
        }

        $rule = WhatsAppRoutingRule::query()
            ->with('primaryProfile')
            ->where('market_id', $request->recipient->platformId)
            ->where('message_type', $request->messageType)
            ->where('enabled', true)
            ->first();

        $profile = $rule?->primaryProfile;

        if (!$profile || !$profile->active) {
            return null;
        }

        return $profile;
    }

    private function createMessage(
        SendRequest $request,
        ?WhatsAppProviderProfile $profile,
        string $status,
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): WhatsAppMessage {
        return WhatsAppMessage::create([
            'platform_id' => $request->recipient->platformId,
            'direction' => 'outbound',
            'engine' => $profile?->engine ?? 'meta_cloud_api',
            'provider_profile_id' => $profile?->id,
            'client_id' => $request->recipient->clientId,
            'lead_id' => $request->recipient->leadId,
            'deal_id' => $request->recipient->dealId,
            'payment_id' => $request->recipient->paymentId,
            'template_id' => $request->templateId,
            'phone_e164' => $request->recipient->phoneE164,
            'body' => $request->body,
            'media_url' => $request->mediaUrl,
            'idempotency_key' => $request->idempotencyKey,
            'status' => $status,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'failed_at' => in_array($status, ['failed', 'rejected', 'suppressed'], true) ? now() : null,
        ]);
    }

    private function recordSentEvents(WhatsAppMessage $message, SendRequest $request): void
    {
        $this->recordTimelineEvent($message, $request, CrmAuditAction::WHATSAPP_SENT, [
            'status' => $message->status,
            'provider_message_id' => $message->provider_message_id,
        ]);

        $this->recordAudit($message, $request, CrmAuditAction::WHATSAPP_SENT, [
            'status' => $message->status,
            'provider_message_id' => $message->provider_message_id,
        ]);
    }

    private function recordFailureEvents(WhatsAppMessage $message, SendRequest $request, ?string $reason): void
    {
        $this->recordTimelineEvent($message, $request, CrmAuditAction::WHATSAPP_FAILED, [
            'status' => $message->status,
            'error_code' => $message->error_code,
            'error_message' => $reason,
        ]);

        $this->recordAudit($message, $request, CrmAuditAction::WHATSAPP_FAILED, [
            'status' => $message->status,
            'error_code' => $message->error_code,
            'error_message' => $reason,
        ], $reason);
    }

    private function recordTimelineEvent(WhatsAppMessage $message, SendRequest $request, string $eventType, array $content): void
    {
        if (!empty($request->context['suppress_gateway_timeline'])) {
            return;
        }

        [$entityType, $entityId] = $this->timelineEntity($request);

        if (!$entityType || !$entityId) {
            return;
        }

        TimelineEvent::create([
            'platform_id' => $request->recipient->platformId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_type' => $eventType,
            'actor_id' => $request->context['actor_id'] ?? null,
            'content' => array_merge($content, [
                'whatsapp_message_id' => $message->id,
                'phone_e164' => $message->phone_e164,
            ]),
            'created_at' => now(),
        ]);
    }

    private function recordAudit(WhatsAppMessage $message, SendRequest $request, string $action, array $afterState, ?string $reason = null): void
    {
        $this->auditService->record([
            'platform_id' => $request->recipient->platformId,
            'actor_id' => $request->context['actor_id'] ?? null,
            'action' => $action,
            'entity_type' => 'whatsapp_message',
            'entity_id' => $message->id,
            'after_state' => $afterState,
            'reason' => $reason,
        ]);
    }

    private function timelineEntity(SendRequest $request): array
    {
        if ($request->recipient->clientId) {
            return ['client', $request->recipient->clientId];
        }

        if ($request->recipient->leadId) {
            return ['lead', $request->recipient->leadId];
        }

        if ($request->recipient->dealId) {
            return ['deal', $request->recipient->dealId];
        }

        if ($request->recipient->paymentId) {
            return ['payment', $request->recipient->paymentId];
        }

        return [null, null];
    }
}
