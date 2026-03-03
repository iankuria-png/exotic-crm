<?php

namespace App\Services\PushNotification;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class WonderPushProvider implements PushProviderInterface
{
    public function id(): string
    {
        return 'wonderpush';
    }

    public function configured(array $config): bool
    {
        return !empty($config['access_token']) && !empty($config['project_id']);
    }

    public function send(array $notification, array $config, array $context = []): array
    {
        if (!$this->configured($config)) {
            return [
                'success' => false,
                'provider' => $this->id(),
                'provider_notification_id' => null,
                'provider_response' => 'WonderPush credentials are incomplete.',
            ];
        }

        $payload = [
            'projectId' => (string) ($config['project_id'] ?? ''),
            'targetSegmentIds' => '@ALL',
            'campaignId' => (string) ($notification['campaign_name'] ?? ''),
            'notification' => [
                'alert' => [
                    'title' => mb_substr((string) ($notification['title'] ?? ''), 0, 100),
                    'text' => mb_substr((string) ($notification['message'] ?? ''), 0, 255),
                ],
                'targetUrl' => (string) ($notification['target_url'] ?? ''),
                'web' => [
                    'icon' => $notification['icon_url'] ?? null,
                    'image' => $notification['image_url'] ?? null,
                    'buttons' => $this->normalizeButtons($notification['buttons'] ?? []),
                ],
            ],
            'scheduledAt' => $notification['schedule_at'] ?? null,
            'ttl' => $notification['ttl'] ?? null,
        ];

        if ($payload['campaignId'] === '') {
            unset($payload['campaignId']);
        }

        if (empty($payload['notification']['web']['buttons'])) {
            unset($payload['notification']['web']['buttons']);
        }

        if (is_null($payload['scheduledAt']) || $payload['scheduledAt'] === '') {
            unset($payload['scheduledAt']);
        }

        if (is_null($payload['ttl']) || $payload['ttl'] === '') {
            unset($payload['ttl']);
        }

        $endpoint = 'https://management-api.wonderpush.com/v1/deliveries?accessToken=' . urlencode((string) $config['access_token']);

        $response = Http::acceptJson()
            ->timeout(20)
            ->retry(2, 500)
            ->post($endpoint, $payload);

        $raw = $response->json();
        $body = is_array($raw) ? $raw : ['body' => $response->body()];
        $providerNotificationId = $this->extractCampaignId($body, $payload['campaignId'] ?? null);

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
        if (!$this->configured($config) || trim($providerNotificationId) === '') {
            return null;
        }

        $endpoint = 'https://management-api.wonderpush.com/v1/stats/events';

        $response = Http::acceptJson()
            ->timeout(20)
            ->retry(2, 500)
            ->get($endpoint, [
                'accessToken' => (string) $config['access_token'],
                'campaignId' => $providerNotificationId,
            ]);

        if (!$response->successful()) {
            return null;
        }

        $raw = $response->json();
        $body = is_array($raw) ? $raw : ['body' => $response->body()];

        return [
            'total_sent' => (int) $this->firstValue($body, ['total_sent', 'sent', 'stats.sent', 'summary.sent', 'totals.sent'], 0),
            'delivered' => $this->nullableInt($this->firstValue($body, ['delivered', 'stats.delivered', 'summary.delivered', 'totals.delivered'])),
            'clicked' => $this->nullableInt($this->firstValue($body, ['clicked', 'stats.clicked', 'summary.clicked', 'totals.clicked'])),
            'failed' => $this->nullableInt($this->firstValue($body, ['failed', 'stats.failed', 'summary.failed', 'totals.failed'])),
            'closed' => $this->nullableInt($this->firstValue($body, ['closed', 'stats.closed', 'summary.closed', 'totals.closed'])),
            'raw' => $body,
        ];
    }

    public function getSubscriberCount(array $config): ?array
    {
        if (!$this->configured($config)) {
            return null;
        }

        $response = Http::acceptJson()
            ->timeout(20)
            ->retry(2, 500)
            ->get('https://management-api.wonderpush.com/v1/stats/events', [
                'accessToken' => (string) $config['access_token'],
            ]);

        if (!$response->successful()) {
            return null;
        }

        $raw = $response->json();
        $body = is_array($raw) ? $raw : [];

        return [
            'total' => (int) $this->firstValue($body, ['total_subscribers', 'stats.total_subscribers', 'summary.total_subscribers', 'totals.subscribers'], 0),
            'active' => (int) $this->firstValue($body, ['active_subscribers', 'stats.active_subscribers', 'summary.active_subscribers'], 0),
        ];
    }

    private function normalizeButtons($buttons): array
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
                'label' => $text,
                'url' => $url,
            ];
        }, $buttons)));
    }

    private function extractCampaignId(array $body, ?string $fallback = null): ?string
    {
        $value = $this->firstValue($body, ['campaign_id', 'campaignId', 'id', 'data.id', 'data.campaign_id', 'data.campaignId'], $fallback);

        if (is_null($value)) {
            return null;
        }

        $id = trim((string) $value);

        return $id === '' ? null : $id;
    }

    private function firstValue(array $data, array $paths, $default = null)
    {
        foreach ($paths as $path) {
            $value = Arr::get($data, $path);
            if (!is_null($value)) {
                return $value;
            }
        }

        return $default;
    }

    private function nullableInt($value): ?int
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
