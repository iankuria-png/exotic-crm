<?php

namespace App\Services\Messaging\Engines;

use App\Models\WhatsAppProviderProfile;
use App\Services\Messaging\BaileysSenderPool;
use App\Services\Messaging\NormalizedInbound;
use App\Services\Messaging\SendRequest;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\Sidecar\HmacSigner;
use App\Services\Messaging\WhatsAppEngineInterface;
use Illuminate\Support\Facades\Http;

class BaileysEngine implements WhatsAppEngineInterface
{
    public function __construct(
        private readonly BaileysSenderPool $senderPool,
        private readonly HmacSigner $signer,
    ) {
    }

    public function send(SendRequest $request): SendResult
    {
        $profile = $request->profile;
        if (!$profile) {
            return SendResult::failed('failed', 'missing_profile', 'Baileys profile is required.');
        }

        $sender = $this->senderPool->pickFor($profile);
        if (!$sender) {
            return SendResult::failed('failed', 'no_sender_available', 'No connected Baileys sender has capacity.');
        }

        $attemptUuid = (string) ($request->context['attempt_uuid'] ?? '');
        if ($attemptUuid === '') {
            return SendResult::failed('failed', 'missing_attempt_uuid', 'Baileys sends require a gateway attempt id.');
        }
        $payload = [
            'senderId' => (int) $sender->id,
            'to' => $request->recipient->phoneE164,
            'body' => $request->body,
            'mediaUrl' => $request->mediaUrl,
            'attemptUuid' => $attemptUuid,
            'messageType' => $request->messageType,
        ];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $url = rtrim((string) ($profile->baileys_sidecar_url_override ?: config('services.whatsapp.sidecar_url')), '/');

        $response = Http::timeout(15)
            ->withHeaders([
                'X-Signature' => $this->signer->sign((string) $body),
                'Idempotency-Key' => $attemptUuid,
                'Content-Type' => 'application/json',
            ])
            ->post($url . '/messages', $payload);

        if ($response->successful() || $response->status() === 202) {
            $this->senderPool->recordAccepted($sender);
            $sidecarId = (string) ($response->json('attemptUuid') ?: $response->json('messageId') ?: $attemptUuid);

            return SendResult::sent(
                null,
                $response->json() ?: [],
                senderId: (int) $sender->id,
                attemptUuid: $sidecarId,
                costMicros: 0
            );
        }

        $this->senderPool->recordFailure($sender);

        return SendResult::failed(
            'failed',
            (string) ($response->json('error_code') ?: 'sidecar_send_failed'),
            (string) ($response->json('message') ?: $response->body() ?: 'Baileys sidecar rejected the send.'),
            $response->json() ?: [],
            senderId: (int) $sender->id,
            attemptUuid: $attemptUuid
        );
    }

    public function verifyInbound(array $payload, string $signature): ?NormalizedInbound
    {
        return null;
    }

    public function healthCheck(WhatsAppProviderProfile $profile): array
    {
        $url = rtrim((string) ($profile->baileys_sidecar_url_override ?: config('services.whatsapp.sidecar_url')), '/');
        $response = Http::timeout(5)->get($url . '/healthz');

        return [
            'ok' => $response->ok(),
            'status' => $response->status(),
            'body' => $response->json() ?: $response->body(),
        ];
    }

    public function id(): string
    {
        return 'baileys';
    }
}
