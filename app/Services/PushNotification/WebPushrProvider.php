<?php

namespace App\Services\PushNotification;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class WebPushrProvider implements PushProviderInterface
{
    public function id(): string
    {
        return 'webpushr';
    }

    public function configured(array $config): bool
    {
        return !empty($config['api_key']) && !empty($config['auth_token']);
    }

    public function send(array $notification, array $config, array $context = []): array
    {
        if (!$this->configured($config)) {
            return [
                'success' => false,
                'provider' => $this->id(),
                'provider_notification_id' => null,
                'provider_response' => 'WebPushr credentials are incomplete.',
            ];
        }

        $payload = [
            'title' => mb_substr((string) ($notification['title'] ?? ''), 0, 100),
            'message' => mb_substr((string) ($notification['message'] ?? ''), 0, 255),
            'target_url' => (string) ($notification['target_url'] ?? ''),
            'icon' => $notification['icon_url'] ?? null,
            'image' => $notification['image_url'] ?? null,
            'name' => $notification['campaign_name'] ?? null,
            'send_at' => $notification['schedule_at'] ?? null,
            'expire_push' => $notification['ttl'] ?? null,
            'action_buttons' => $this->normalizeButtons($notification['buttons'] ?? []),
        ];

        $payload = array_filter($payload, static fn ($value) => !is_null($value) && $value !== '');

        $response = Http::acceptJson()
            ->withHeaders([
                'webpushrKey' => (string) $config['api_key'],
                'webpushrAuthToken' => (string) $config['auth_token'],
            ])
            ->timeout(20)
            ->retry(2, 500)
            ->post('https://api.webpushr.com/v1/notification/send/all', $payload);

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
        if (!$this->configured($config) || trim($providerNotificationId) === '') {
            return null;
        }

        $response = Http::acceptJson()
            ->withHeaders([
                'webpushrKey' => (string) $config['api_key'],
                'webpushrAuthToken' => (string) $config['auth_token'],
            ])
            ->timeout(20)
            ->retry(2, 500)
            ->get('https://api.webpushr.com/v1/notification/status/id/' . urlencode($providerNotificationId));

        if (!$response->successful()) {
            return null;
        }

        $raw = $response->json();
        $body = is_array($raw) ? $raw : ['body' => $response->body()];

        return [
            'total_sent' => (int) $this->firstValue($body, ['total_sent', 'data.total_sent', 'stats.total_sent', 'sent', 'data.sent'], 0),
            'delivered' => $this->nullableInt($this->firstValue($body, ['delivered', 'data.delivered', 'stats.delivered'])),
            'clicked' => $this->nullableInt($this->firstValue($body, ['clicked', 'data.clicked', 'stats.clicked'])),
            'failed' => $this->nullableInt($this->firstValue($body, ['failed', 'data.failed', 'stats.failed'])),
            'closed' => $this->nullableInt($this->firstValue($body, ['closed', 'data.closed', 'stats.closed'])),
            'raw' => $body,
        ];
    }

    public function getSubscriberCount(array $config): ?array
    {
        if (!$this->configured($config)) {
            return null;
        }

        $response = Http::acceptJson()
            ->withHeaders([
                'webpushrKey' => (string) $config['api_key'],
                'webpushrAuthToken' => (string) $config['auth_token'],
            ])
            ->timeout(20)
            ->retry(2, 500)
            ->get('https://api.webpushr.com/v1/site/subscriber_count');

        if (!$response->successful()) {
            return null;
        }

        $raw = $response->json();
        $body = is_array($raw) ? $raw : [];

        return [
            'total' => (int) $this->firstValue($body, ['total_life_time_subscribers', 'data.total_life_time_subscribers', 'total_subscribers', 'data.total_subscribers'], 0),
            'active' => (int) $this->firstValue($body, ['active_subscribers', 'data.active_subscribers'], 0),
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
                'title' => $text,
                'url' => $url,
            ];
        }, $buttons)));
    }

    private function extractNotificationId(array $body): ?string
    {
        $value = $this->firstValue($body, ['notification_id', 'id', 'data.id', 'data.notification_id']);

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
