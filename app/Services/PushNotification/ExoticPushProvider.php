<?php

namespace App\Services\PushNotification;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ExoticPushProvider implements PushProviderInterface
{
    public function id(): string
    {
        return 'exoticpush';
    }

    public function configured(array $config): bool
    {
        return !empty($config['site_id'])
            && !empty($config['api_key'])
            && !empty($config['auth_token']);
    }

    public function send(array $notification, array $config, array $context = []): array
    {
        if (!$this->configured($config)) {
            return [
                'success' => false,
                'provider' => $this->id(),
                'provider_notification_id' => null,
                'provider_response' => 'Exotic Push Engine credentials are incomplete.',
            ];
        }

        $payload = [
            'title' => mb_substr((string) ($notification['title'] ?? ''), 0, 150),
            'body' => mb_substr((string) ($notification['message'] ?? ''), 0, 500),
            'url' => (string) ($notification['target_url'] ?? ''),
            'icon' => $notification['icon_url'] ?? null,
            'image' => $notification['image_url'] ?? null,
        ];

        $payload = array_filter($payload, static fn ($value) => !is_null($value) && $value !== '');

        $request = $this->baseRequest($config);
        $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));
        if ($idempotencyKey !== '') {
            $request = $request->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ]);
        }

        $response = $request->post($this->siteEndpoint($config, '/rest-api/notifications'), $payload);

        $raw = $response->json();
        $body = is_array($raw) ? $raw : ['body' => $response->body()];
        $providerNotificationId = $this->extractNotificationId($body);
        $success = $response->successful() && data_get($body, 'success') === true;

        if ($success) {
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

        $response = $this->baseRequest($config)
            ->get($this->siteEndpoint($config, '/rest-api/notifications/' . rawurlencode($providerNotificationId) . '/status'));

        if (!$response->successful()) {
            return null;
        }

        $raw = $response->json();
        $body = is_array($raw) ? $raw : ['body' => $response->body()];

        return [
            'total_sent' => (int) $this->firstValue($body, ['data.sent', 'sent'], 0),
            'delivered' => $this->nullableInt($this->firstValue($body, ['data.delivered', 'delivered'])),
            'clicked' => $this->nullableInt($this->firstValue($body, ['data.clicked', 'clicked'])),
            'failed' => $this->nullableInt($this->firstValue($body, ['data.failed', 'failed'])),
            'closed' => null,
            'raw' => $body,
        ];
    }

    public function getSubscriberCount(array $config): ?array
    {
        if (!$this->configured($config)) {
            return null;
        }

        $response = $this->baseRequest($config)
            ->get($this->siteEndpoint($config, '/rest-api/subscribers/count'));

        if (!$response->successful()) {
            $raw = $response->json();
            $body = is_array($raw) ? $raw : ['body' => $response->body()];
            $description = trim((string) $this->firstValue($body, ['message', 'error', 'description'], ''));
            if ($description === '') {
                $description = trim((string) json_encode($body));
            }

            throw new RuntimeException(sprintf(
                'Exotic Push Engine subscriber count request failed (%d): %s',
                $response->status(),
                $description !== '' ? $description : 'Unknown error'
            ));
        }

        $raw = $response->json();
        $body = is_array($raw) ? $raw : [];
        $count = (int) $this->firstValue($body, ['data.subscriberCount', 'subscriberCount'], 0);

        return [
            'total' => $count,
            'active' => $count,
        ];
    }

    private function baseRequest(array $config): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-EPE-Site-Key' => (string) $config['api_key'],
                'Authorization' => 'Bearer ' . (string) $config['auth_token'],
            ])
            ->timeout(10)
            ->retry(2, 500, throw: false);
    }

    private function siteEndpoint(array $config, string $path): string
    {
        $baseUrl = rtrim((string) config('services.exotic_push.base_url', 'https://push.exotic-online.com/api'), '/');
        $siteId = rawurlencode((string) $config['site_id']);

        return $baseUrl . '/sites/' . $siteId . $path;
    }

    private function extractNotificationId(array $body): ?string
    {
        $value = $this->firstValue($body, ['data.notificationId', 'notificationId', 'notification_id', 'id']);

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
