<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

class BriqSmsProvider implements SmsProviderInterface
{
    public function id(): string
    {
        return 'briq';
    }

    public function label(): string
    {
        return 'Briq (Tanzania)';
    }

    public function credentialFields(): array
    {
        return [
            [
                'key' => 'base_url',
                'label' => 'Base URL',
                'type' => 'url',
                'required' => true,
                'default' => 'https://karibu.briq.tz',
            ],
            [
                'key' => 'api_key',
                'label' => 'API Key',
                'type' => 'password',
                'required' => true,
                'secret' => true,
            ],
            [
                'key' => 'sender_id',
                'label' => 'Sender ID',
                'type' => 'text',
                'required' => true,
            ],
        ];
    }

    public function configured(array $config): bool
    {
        return !empty($config['base_url'])
            && !empty($config['api_key'])
            && !empty($config['sender_id']);
    }

    public function send(string $phone, string $message, array $config, array $context = []): array
    {
        if (!$this->configured($config)) {
            return [
                'success' => false,
                'status' => 'failed',
                'provider' => $this->id(),
                'provider_response' => 'Briq credentials are incomplete.',
            ];
        }

        $baseUrl = rtrim((string) $config['base_url'], '/');

        $response = Http::timeout(20)
            ->retry(2, 500)
            ->withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-API-Key' => (string) $config['api_key'],
            ])
            ->post($baseUrl . '/v1/message/send-instant', [
                'content' => $message,
                'recipients' => [$phone],
                'sender_id' => (string) $config['sender_id'],
                'campaign_id' => $context['campaign_id'] ?? null,
                'groups' => null,
            ]);

        $json = $response->json();
        $success = $response->successful() && is_array($json) && !empty($json['success']);

        return [
            'success' => $success,
            'status' => $success ? 'sent' : 'failed',
            'provider' => $this->id(),
            'provider_response' => $json ?: $response->body(),
            'job_id' => is_array($json) ? ($json['job_id'] ?? null) : null,
            'http_code' => $response->status(),
        ];
    }
}
