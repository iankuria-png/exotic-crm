<?php

namespace App\Services\PushNotification;

use App\Services\PushNotification\Concerns\ClassifiesProviderFailure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class ExoticPushProvider implements PushProviderInterface
{
    use ClassifiesProviderFailure;

    public function id(): string
    {
        return 'exoticpush';
    }

    public function configured(array $config): bool
    {
        return ! empty($config['site_id'])
            && ! empty($config['api_key'])
            && ! empty($config['auth_token']);
    }

    public function send(array $notification, array $config, array $context = []): array
    {
        $siteId = trim((string) ($config['site_id'] ?? ''));
        $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));
        $requestTimezone = trim((string) ($context['request_timezone'] ?? config('app.timezone', 'UTC'))) ?: 'UTC';
        $requestTimestamp = now($requestTimezone);
        $endpoint = $siteId !== '' ? $this->siteEndpoint($config, '/rest-api/notifications') : null;
        $debug = [
            'provider' => $this->id(),
            'request_timestamp' => $requestTimestamp->toIso8601String(),
            'request_timezone' => $requestTimestamp->getTimezone()->getName(),
            'request_method' => 'POST',
            'request_url' => $endpoint,
            'site_id' => $siteId !== '' ? $siteId : null,
            'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
            'campaign_id' => $this->nullableDebugString($context['campaign_id'] ?? null),
            'campaign_item_id' => $this->nullableDebugString($context['campaign_item_id'] ?? null),
            'queue_attempt' => $this->nullableDebugString($context['queue_attempt'] ?? null),
            'queue_max_attempts' => $this->nullableDebugString($context['queue_max_attempts'] ?? null),
            'queue_job_id' => $this->nullableDebugString($context['queue_job_id'] ?? null),
            'http_status' => null,
            'response_headers' => [],
            'response_body' => null,
            'notification_id' => null,
            'job_id' => null,
            'provider_code' => null,
            'provider_message' => null,
        ];

        $payload = [
            'title' => mb_substr((string) ($notification['title'] ?? ''), 0, 150),
            'body' => mb_substr((string) ($notification['message'] ?? ''), 0, 500),
            'url' => (string) ($notification['target_url'] ?? ''),
            'icon' => $notification['icon_url'] ?? null,
            'image' => $notification['image_url'] ?? null,
        ];

        $payload = array_filter($payload, static fn ($value) => ! is_null($value) && $value !== '');
        $debug['request_payload'] = $this->sanitizeDebugBody($payload);

        if (! $this->configured($config)) {
            return [
                'success' => false,
                'provider' => $this->id(),
                'provider_notification_id' => null,
                'provider_response' => [
                    'code' => 'epe_credentials_missing',
                    'message' => 'Exotic Push Engine credentials are incomplete.',
                ],
                'provider_debug' => [
                    ...$debug,
                    'provider_code' => 'epe_credentials_missing',
                    'provider_message' => 'Exotic Push Engine credentials are incomplete.',
                ],
            ];
        }

        $request = $this->baseRequest($config);
        if ($idempotencyKey !== '') {
            $request = $request->withHeaders([
                'Idempotency-Key' => $idempotencyKey,
            ]);
        }

        $response = $request->post((string) $endpoint, $payload);

        $raw = $response->json();
        $body = is_array($raw) ? $raw : ['body' => $response->body()];
        $providerNotificationId = $this->extractNotificationId($body);
        $providerJobId = $this->extractJobId($body);
        $debug = [
            ...$debug,
            'http_status' => $response->status(),
            'response_headers' => $this->safeResponseHeaders($response->headers()),
            'response_body' => $this->sanitizeDebugBody($body),
            'notification_id' => $providerNotificationId,
            'job_id' => $providerJobId,
        ];
        $success = $response->successful() && data_get($body, 'success') === true;

        if ($success) {
            return [
                'success' => true,
                'provider' => $this->id(),
                'provider_notification_id' => $providerNotificationId,
                'provider_response' => $body,
                'provider_debug' => [
                    ...$debug,
                    'provider_code' => 'epe_queued',
                    'provider_message' => 'Queued by Exotic Push Engine.',
                ],
            ];
        }

        [$code, $message] = $this->classifyProviderFailure('epe', $response->status(), $body);
        $debug = [
            ...$debug,
            'provider_code' => $code,
            'provider_message' => $message,
        ];

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
            'provider_debug' => $debug,
        ];
    }

    public function getStatus(string $providerNotificationId, array $config): ?array
    {
        if (! $this->configured($config) || trim($providerNotificationId) === '') {
            return null;
        }

        $response = $this->baseRequest($config)
            ->get($this->siteEndpoint($config, '/rest-api/notifications/'.rawurlencode($providerNotificationId).'/status'));

        if (! $response->successful()) {
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
        if (! $this->configured($config)) {
            return null;
        }

        $response = $this->baseRequest($config)
            ->get($this->siteEndpoint($config, '/rest-api/subscribers/count'));

        if (! $response->successful()) {
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
                'Authorization' => 'Bearer '.(string) $config['auth_token'],
            ])
            ->timeout(10)
            ->retry(2, 500, throw: false);
    }

    private function siteEndpoint(array $config, string $path): string
    {
        $baseUrl = rtrim((string) config('services.exotic_push.base_url', 'https://push.exotic-online.com/api'), '/');
        $siteId = rawurlencode((string) $config['site_id']);

        return $baseUrl.'/sites/'.$siteId.$path;
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

    private function extractJobId(array $body): ?string
    {
        $value = $this->firstValue($body, ['data.jobId', 'data.jobID', 'jobId', 'jobID', 'job_id']);

        if (is_null($value)) {
            return null;
        }

        $id = trim((string) $value);

        return $id === '' ? null : $id;
    }

    private function sanitizeDebugBody(array $body): array
    {
        return $this->truncateDebugValue($body);
    }

    private function safeResponseHeaders(array $headers): array
    {
        $allowedHeaders = [
            'cf-ray',
            'content-type',
            'date',
            'retry-after',
            'server',
            'traceparent',
            'x-correlation-id',
            'x-request-id',
            'x-trace-id',
        ];
        $safe = [];

        foreach ($headers as $name => $values) {
            $normalizedName = strtolower((string) $name);
            if (! in_array($normalizedName, $allowedHeaders, true)) {
                continue;
            }

            $safe[$normalizedName] = implode(', ', array_map(
                static fn ($value): string => (string) $value,
                (array) $values
            ));
        }

        ksort($safe);

        return $safe;
    }

    private function nullableDebugString(mixed $value): ?string
    {
        if (is_null($value) || $value === '') {
            return null;
        }

        return (string) $value;
    }

    private function truncateDebugValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return Str::limit($value, 12000, '... [truncated]');
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[$key] = $this->truncateDebugValue($item);
            }

            return $normalized;
        }

        return $value;
    }

    private function firstValue(array $data, array $paths, $default = null)
    {
        foreach ($paths as $path) {
            $value = Arr::get($data, $path);
            if (! is_null($value)) {
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
