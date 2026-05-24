<?php

namespace App\Services\Messaging\Inbound;

use App\Models\TimelineEvent;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppProviderProfile;
use App\Services\AuditService;
use App\Services\Messaging\MessageRecipient;
use App\Services\Messaging\MessagingDispatcher;
use App\Services\Messaging\SuppressionService;
use App\Support\CrmAuditAction;

class InboundMessagePipeline
{
    public function __construct(
        private readonly AuditService $auditService,
        private readonly SuppressionService $suppressionService,
        private readonly MessagingDispatcher $messagingDispatcher,
    ) {
    }

    public function handle(WhatsAppMessage $message, WhatsAppProviderProfile $profile, array $rawPayload = []): void
    {
        $this->recordTimelineForMessage($message, CrmAuditAction::WHATSAPP_INBOUND_RECEIVED, [
            'whatsapp_message_id' => $message->id,
            'provider_message_id' => $message->provider_message_id,
            'body' => $message->body,
        ]);

        $this->auditService->record([
            'platform_id' => (int) $message->platform_id,
            'action' => CrmAuditAction::WHATSAPP_INBOUND_RECEIVED,
            'entity_type' => 'whatsapp_message',
            'entity_id' => (int) $message->id,
            'after_state' => [
                'phone_e164' => $message->phone_e164,
                'provider_message_id' => $message->provider_message_id,
            ],
        ]);

        if (!$this->isStopKeyword((string) $message->body)) {
            return;
        }

        $suppression = $this->suppressionService->recordOptOut(
            $message->phone_e164,
            'whatsapp',
            'keyword_stop',
            (int) $message->id,
            (int) $message->platform_id
        );

        $this->auditService->record([
            'platform_id' => (int) $message->platform_id,
            'action' => CrmAuditAction::MESSAGING_OPT_OUT_RECORDED,
            'entity_type' => 'messaging_suppression',
            'entity_id' => (int) $suppression->id,
            'after_state' => [
                'phone_e164' => $suppression->phone_e164,
                'channel' => $suppression->channel,
                'reason' => $suppression->reason,
                'source_message_id' => $message->id,
            ],
        ]);

        $this->sendOptOutConfirmation($message, $profile);
    }

    public function recordTimelineForMessage(WhatsAppMessage $message, string $eventType, array $content): void
    {
        [$entityType, $entityId] = $this->timelineEntity($message);

        if (!$entityType || !$entityId) {
            return;
        }

        TimelineEvent::create([
            'platform_id' => (int) $message->platform_id,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'event_type' => $eventType,
            'content' => $content,
            'created_at' => now(),
        ]);
    }

    private function isStopKeyword(string $body): bool
    {
        $normalized = strtolower(trim($body));
        $keywords = array_filter(array_map(
            static fn ($keyword) => strtolower(trim($keyword)),
            (array) config('services.whatsapp.stop_keywords', [])
        ));

        return $normalized !== '' && in_array($normalized, $keywords, true);
    }

    private function sendOptOutConfirmation(WhatsAppMessage $message, WhatsAppProviderProfile $profile): void
    {
        $templateName = data_get($profile->config_json, 'opt_out_confirmation_template');
        if (!$templateName) {
            return;
        }

        $this->messagingDispatcher->dispatch(
            new MessageRecipient(
                phoneE164: $message->phone_e164,
                platformId: (int) $message->platform_id,
                clientId: $message->client_id,
                leadId: $message->lead_id,
            ),
            '',
            'whatsapp',
            [
                'provider_profile_id' => $profile->id,
                'message_type' => 'transactional',
                'template_name' => $templateName,
                'template_language' => (string) data_get($profile->config_json, 'opt_out_confirmation_language', 'en_US'),
                'idempotency_key' => 'opt-out-confirmation-' . $message->id,
            ]
        );
    }

    private function timelineEntity(WhatsAppMessage $message): array
    {
        if ($message->client_id) {
            return ['client', (int) $message->client_id];
        }

        if ($message->lead_id) {
            return ['lead', (int) $message->lead_id];
        }

        if ($message->deal_id) {
            return ['deal', (int) $message->deal_id];
        }

        if ($message->payment_id) {
            return ['payment', (int) $message->payment_id];
        }

        return [null, null];
    }
}
