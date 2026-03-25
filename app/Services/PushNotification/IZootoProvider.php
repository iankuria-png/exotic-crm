<?php

namespace App\Services\PushNotification;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IZootoProvider implements PushProviderInterface
{
    public function id(): string
    {
        return 'izooto';
    }

    public function configured(array $config): bool
    {
        return !empty($config['api_token']);
    }

    public function send(array $notification, array $config, array $context = []): array
    {
        if (!$this->configured($config)) {
            return [
                'success' => false,
                'provider' => $this->id(),
                'provider_notification_id' => null,
                'provider_response' => 'iZooto credentials are incomplete.',
            ];
        }

        $payload = [
            'campaign_name' => (string) ($notification['campaign_name'] ?? ''),
            'title' => mb_substr((string) ($notification['title'] ?? ''), 0, 100),
            'message' => mb_substr((string) ($notification['message'] ?? ''), 0, 255),
            'landing_url' => (string) ($notification['target_url'] ?? ''),
            'icon_url' => $notification['icon_url'] ?? null,
            'banner_url' => $notification['image_url'] ?? null,
            'actions' => $this->normalizeActions($notification['buttons'] ?? []),
            'ttl' => $notification['ttl'] ?? null,
            'schedule_at' => $notification['schedule_at'] ?? null,
        ];

        if ($payload['campaign_name'] === '') {
            unset($payload['campaign_name']);
        }

        if (empty($payload['actions'])) {
            unset($payload['actions']);
        }

        if (is_null($payload['ttl']) || $payload['ttl'] === '') {
            unset($payload['ttl']);
        }

        if (is_null($payload['schedule_at']) || $payload['schedule_at'] === '') {
            unset($payload['schedule_at']);
        }

        $response = Http::acceptJson()
            ->withHeaders([
                'Authentication-Token' => (string) $config['api_token'],
            ])
            ->timeout(10)
            ->retry(2, 500, throw: false)
            ->post('https://apis.izooto.com/v1/notifications', $payload);

        $raw = $response->json();
        $body = is_array($raw) ? $raw : ['body' => $response->body()];
        $providerNotificationId = $this->extractNotificationId($body);

        if ($response->successful()) {
            return [
                'success' => true,
                'provider' => $this->id(),
                'provider_notification_id' => $providerNotificationId,
                'provider_response' => $body,
            ];
        }

        return [
            'success' => false,
            'provider' => $this->id(),
            'provider_notification_id' => $providerNotificationId,
            'provider_response' => [
                'status' => $response->status(),
                'body' => $body,
            ],
        ];
    }

    public function getStatus(string $providerNotificationId, array $config): ?array
    {
        Log::warning('iZooto REST analytics is not available. Falling back to provider dashboard.', [
            'provider_notification_id' => $providerNotificationId,
        ]);

        return null;
    }

    public function getSubscriberCount(array $config): ?array
    {
        Log::warning('iZooto subscriber count is not available via REST API in current integration.');

        return null;
    }

    private function normalizeActions($buttons): array
    {
        if (!is_array($buttons)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($button) {
            if (!is_array($button)) {
                return null;
            }

            $text = trim((string) ($button['text'] ?? ''));
            $url = trim((string) ($button['url'] ?? ''));

            if ($text === '' || $url === '') {
                return null;
            }

            return [
                'title' => $text,
                'landing_url' => $url,
            ];
        }, $buttons)));
    }

    private function extractNotificationId(array $body): ?string
    {
        $possible = [
            $body['notification_id'] ?? null,
            $body['id'] ?? null,
            $body['data']['id'] ?? null,
            $body['data']['notification_id'] ?? null,
        ];

        foreach ($possible as $value) {
            if (!is_null($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
