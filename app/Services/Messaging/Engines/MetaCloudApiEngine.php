<?php

namespace App\Services\Messaging\Engines;

use App\Models\WhatsAppProviderProfile;
use App\Services\Messaging\NormalizedInbound;
use App\Services\Messaging\SendRequest;
use App\Services\Messaging\SendResult;
use App\Services\Messaging\WhatsAppEngineInterface;
use Illuminate\Support\Facades\Http;

class MetaCloudApiEngine implements WhatsAppEngineInterface
{
    public function id(): string
    {
        return 'meta_cloud_api';
    }

    public function send(SendRequest $request): SendResult
    {
        $profile = $request->profile;

        if (!$profile) {
            return SendResult::failed('failed', 'profile_missing', 'Meta Cloud API profile was not resolved.');
        }

        if (!$profile->meta_access_token || !$profile->meta_phone_number_id) {
            return SendResult::failed('failed', 'profile_incomplete', 'Meta access token and phone number id are required.');
        }

        $response = Http::withToken($profile->meta_access_token)
            ->timeout(15)
            ->retry(2, 500)
            ->post($this->messagesUrl($profile), $this->payload($request));

        $json = $response->json();

        if ($response->successful()) {
            return SendResult::sent(data_get($json, 'messages.0.id'), (array) $json);
        }

        return SendResult::failed(
            $response->status() === 429 ? 'rejected' : 'failed',
            (string) (data_get($json, 'error.code') ?: $response->status()),
            (string) (data_get($json, 'error.message') ?: 'Meta Cloud API request failed.'),
            (array) $json
        );
    }

    public function verifyInbound(array $payload, string $signature): ?NormalizedInbound
    {
        return null;
    }

    public function healthCheck(WhatsAppProviderProfile $profile): array
    {
        if (!$profile->meta_access_token || !$profile->meta_phone_number_id) {
            return [
                'ok' => false,
                'status' => 'profile_incomplete',
                'message' => 'Meta access token and phone number id are required.',
            ];
        }

        $response = Http::withToken($profile->meta_access_token)
            ->timeout(10)
            ->get("https://graph.facebook.com/{$profile->apiVersion()}/{$profile->meta_phone_number_id}", [
                'fields' => 'id,display_phone_number,verified_name',
            ]);

        return [
            'ok' => $response->successful(),
            'status' => $response->successful() ? 'healthy' : 'failed',
            'message' => $response->successful()
                ? 'Meta phone number is reachable.'
                : (string) (data_get($response->json(), 'error.message') ?: 'Meta health check failed.'),
            'data' => $response->json(),
        ];
    }

    private function messagesUrl(WhatsAppProviderProfile $profile): string
    {
        return "https://graph.facebook.com/{$profile->apiVersion()}/{$profile->meta_phone_number_id}/messages";
    }

    private function payload(SendRequest $request): array
    {
        $base = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $request->recipient->phoneE164,
        ];

        if ($request->templateName) {
            return array_merge($base, [
                'type' => 'template',
                'template' => array_filter([
                    'name' => $request->templateName,
                    'language' => ['code' => $request->templateLanguage],
                    'components' => $request->templateComponents ?: null,
                ], static fn ($value) => $value !== null),
            ]);
        }

        if ($request->mediaUrl) {
            return array_merge($base, [
                'type' => 'image',
                'image' => [
                    'link' => $request->mediaUrl,
                    'caption' => $request->body,
                ],
            ]);
        }

        return array_merge($base, [
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $request->body,
            ],
        ]);
    }
}
