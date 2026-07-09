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
                'provider_response' => [
                    'code' => 'epe_credentials_missing',
                    'message' => 'Exotic Push Engine credentials are incomplete.',
                ],
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

        [$code, $message] = $this->classifyFailure($response->status(), $body);

        return [
            'success' => false,
            'provider' => $this->id(),
            'provider_notification_id' => $providerNotificationId,
            'provider_response' => [
                'code' => $code,
                'message' => $message,
                'status' => $response->status(),
                'body' => $body,
            ],
        ];
    }

    /**
     * Map an EPE failure response to a stable code + human message. The code is a
     * short identifier the CRM UI uses to render friendly badges; the message is a
     * one-line summary suitable for an item's error_message column.
     *
     * @return array{0:string,1:string}
     */
    private function classifyFailure(int $status, array $body): array
    {
        $detail = $this->extractServerMessage($body);

        // 2xx with success:false is an application-level rejection (bad URL, no
        // subscribers, etc). Prefer the server-supplied reason when available.
        if ($status >= 200 && $status < 300) {
            return [
                'epe_rejected',
                $detail !== '' ? $detail : 'Push Engine rejected the notification.',
            ];
        }

        return match (true) {
            $status === 401 => ['epe_unauthorized', $detail !== '' ? $detail : 'Auth token invalid or rotated (401).'],
            $status === 403 => ['epe_forbidden', $detail !== '' ? $detail : 'Site key not permitted for this action (403).'],
            $status === 404 => ['epe_not_found', $detail !== '' ? $detail : 'Site or notification not found (404).'],
            $status === 422 => ['epe_validation', $detail !== '' ? $detail : 'Payload rejected as invalid (422).'],
            $status === 429 => ['epe_rate_limited', $this->formatRateLimitMessage($body, $detail)],
            $status >= 500 => ['epe_provider_error', $detail !== '' ? $detail : "Push Engine internal error ({$status})."],
            default => ['epe_http_error', $detail !== '' ? $detail : "Push Engine returned HTTP {$status}."],
        };
    }

    private function extractServerMessage(array $body): string
    {
        foreach (['error.message', 'error', 'message', 'description'] as $path) {
            $value = Arr::get($body, $path);
            if (is_string($value) && trim($value) !== '') {
                return mb_substr(trim($value), 0, 240);
            }
        }

        return '';
    }

    private function formatRateLimitMessage(array $body, string $detail): string
    {
        $retryAfterMs = Arr::get($body, 'retryAfterMs');
        if (is_numeric($retryAfterMs) && (int) $retryAfterMs > 0) {
            $seconds = max(1, (int) ceil(((int) $retryAfterMs) / 1000));
            $base = $detail !== '' ? $detail : 'Rate limit exceeded';
            return "{$base} (retry after {$seconds}s).";
        }

        return $detail !== '' ? $detail : 'Rate limit exceeded (429).';
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
