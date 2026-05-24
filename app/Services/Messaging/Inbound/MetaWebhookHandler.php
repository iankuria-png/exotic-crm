<?php

namespace App\Services\Messaging\Inbound;

use App\Models\MessagingWebhookEvent;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppProviderProfile;
use App\Support\CrmAuditAction;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;

class MetaWebhookHandler
{
    public function __construct(
        private readonly InboundMessagePipeline $inboundMessagePipeline,
    ) {
    }

    public function verifyChallenge(string $mode, string $token, string $challenge): ?string
    {
        if ($mode !== 'subscribe' || $token === '' || $challenge === '') {
            return null;
        }

        $matched = WhatsAppProviderProfile::query()
            ->where('engine', 'meta_cloud_api')
            ->where('active', true)
            ->get()
            ->contains(fn (WhatsAppProviderProfile $profile) => hash_equals((string) $profile->meta_webhook_verify_token, $token));

        return $matched ? $challenge : null;
    }

    public function handle(string $rawBody, array $payload, string $signature): array
    {
        $profile = $this->resolveProfile($payload);

        if (!$profile || !$this->signatureIsValid($profile, $rawBody, $signature)) {
            return ['verified' => false, 'processed' => 0, 'duplicates' => 0];
        }

        $processed = 0;
        $duplicates = 0;
        $payloadHash = hash('sha256', $rawBody);

        foreach ($this->changes($payload) as $change) {
            $value = $change['value'] ?? [];

            foreach ((array) ($value['statuses'] ?? []) as $status) {
                $eventId = $this->statusEventId($status);
                if (!$eventId || !$this->recordWebhookEvent($eventId, $payloadHash)) {
                    $duplicates++;
                    continue;
                }

                $this->processStatus($status);
                $processed++;
            }

            foreach ((array) ($value['messages'] ?? []) as $messagePayload) {
                $eventId = $this->messageEventId($messagePayload);
                if (!$eventId || !$this->recordWebhookEvent($eventId, $payloadHash)) {
                    $duplicates++;
                    continue;
                }

                $this->processInboundMessage($profile, $messagePayload);
                $processed++;
            }
        }

        return ['verified' => true, 'processed' => $processed, 'duplicates' => $duplicates];
    }

    private function resolveProfile(array $payload): ?WhatsAppProviderProfile
    {
        $phoneNumberId = null;

        foreach ($this->changes($payload) as $change) {
            $phoneNumberId = data_get($change, 'value.metadata.phone_number_id');
            if ($phoneNumberId) {
                break;
            }
        }

        if (!$phoneNumberId) {
            return null;
        }

        return WhatsAppProviderProfile::query()
            ->where('engine', 'meta_cloud_api')
            ->where('meta_phone_number_id', (string) $phoneNumberId)
            ->where('active', true)
            ->first();
    }

    private function signatureIsValid(WhatsAppProviderProfile $profile, string $rawBody, string $signature): bool
    {
        $provided = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        if (!$profile->meta_app_secret || $provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $profile->meta_app_secret);

        return hash_equals($expected, $provided);
    }

    private function changes(array $payload): array
    {
        $changes = [];

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $changes[] = $change;
            }
        }

        return $changes;
    }

    private function recordWebhookEvent(string $externalEventId, string $payloadHash): bool
    {
        try {
            MessagingWebhookEvent::create([
                'engine' => 'meta_cloud_api',
                'external_event_id' => $externalEventId,
                'received_at' => now(),
                'payload_hash' => $payloadHash,
            ]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function processStatus(array $status): void
    {
        $providerMessageId = (string) ($status['id'] ?? '');
        if ($providerMessageId === '') {
            return;
        }

        $message = WhatsAppMessage::query()
            ->where('provider_message_id', $providerMessageId)
            ->first();

        if (!$message) {
            return;
        }

        $statusName = (string) ($status['status'] ?? 'delivered');
        $timestamp = isset($status['timestamp'])
            ? now()->setTimestamp((int) $status['timestamp'])
            : now();
        $errors = (array) ($status['errors'] ?? []);
        $firstError = $errors[0] ?? null;

        $updates = ['status' => $this->canonicalStatus($statusName)];
        if ($updates['status'] === 'delivered') {
            $updates['delivered_at'] = $timestamp;
        } elseif ($updates['status'] === 'read') {
            $updates['read_at'] = $timestamp;
        } elseif ($updates['status'] === 'failed') {
            $updates['failed_at'] = $timestamp;
            $updates['error_code'] = (string) data_get($firstError, 'code', $message->error_code);
            $updates['error_message'] = (string) data_get($firstError, 'message', $message->error_message);
        }

        $message->forceFill($updates)->save();
        $this->recordStatusTimeline($message->refresh());
    }

    private function processInboundMessage(WhatsAppProviderProfile $profile, array $messagePayload): void
    {
        $phone = PhoneNormalizer::normalize((string) ($messagePayload['from'] ?? ''));
        $providerMessageId = (string) ($messagePayload['id'] ?? '');

        if (!$phone || $providerMessageId === '') {
            return;
        }

        $body = (string) data_get($messagePayload, 'text.body', '');
        $clientId = DB::table('clients')
            ->where('platform_id', $profile->market_id)
            ->where('phone_normalized', $phone)
            ->value('id');
        $leadId = $clientId ? null : DB::table('leads')
            ->where('platform_id', $profile->market_id)
            ->where('phone_normalized', $phone)
            ->value('id');

        $message = WhatsAppMessage::create([
            'platform_id' => $profile->market_id,
            'direction' => 'inbound',
            'engine' => 'meta_cloud_api',
            'provider_profile_id' => $profile->id,
            'client_id' => $clientId ? (int) $clientId : null,
            'lead_id' => $leadId ? (int) $leadId : null,
            'phone_e164' => $phone,
            'body' => $body,
            'provider_message_id' => $providerMessageId,
            'status' => 'received',
        ]);

        $this->inboundMessagePipeline->handle($message, $profile, $messagePayload);
    }

    private function canonicalStatus(string $status): string
    {
        return match ($status) {
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            default => 'delivered',
        };
    }

    private function recordStatusTimeline(WhatsAppMessage $message): void
    {
        $eventType = match ($message->status) {
            'delivered' => CrmAuditAction::WHATSAPP_DELIVERED,
            'read' => CrmAuditAction::WHATSAPP_READ,
            'failed' => CrmAuditAction::WHATSAPP_FAILED,
            default => null,
        };

        if (!$eventType) {
            return;
        }

        $this->inboundMessagePipeline->recordTimelineForMessage($message, $eventType, [
            'whatsapp_message_id' => $message->id,
            'provider_message_id' => $message->provider_message_id,
            'status' => $message->status,
        ]);
    }

    private function messageEventId(array $message): ?string
    {
        $id = (string) ($message['id'] ?? '');

        return $id !== '' ? 'message:' . $id : null;
    }

    private function statusEventId(array $status): ?string
    {
        $id = (string) ($status['id'] ?? '');
        $statusName = (string) ($status['status'] ?? '');

        return $id !== '' && $statusName !== '' ? 'status:' . $id . ':' . $statusName : null;
    }
}
