<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

class AfricasTalkingSmsProvider implements SmsProviderInterface
{
    public function id(): string
    {
        return 'africastalking';
    }

    public function label(): string
    {
        return "Africa's Talking";
    }

    public function credentialFields(): array
    {
        return [
            [
                'key' => 'endpoint',
                'label' => 'Endpoint',
                'type' => 'url',
                'required' => false,
                'default' => 'https://api.africastalking.com/version1/messaging',
            ],
            [
                'key' => 'username',
                'label' => 'Username',
                'type' => 'text',
                'required' => true,
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
                'required' => false,
            ],
        ];
    }

    public function configured(array $config): bool
    {
        return !empty($config['username']) && !empty($config['api_key']);
    }

    public function send(string $phone, string $message, array $config, array $context = []): array
    {
        if (!$this->configured($config)) {
            return [
                'success' => false,
                'status' => 'failed',
                'provider' => $this->id(),
                'provider_response' => "Africa's Talking credentials are incomplete.",
            ];
        }

        $endpoint = $config['endpoint'] ?? 'https://api.africastalking.com/version1/messaging';

        $payload = [
            'username' => $config['username'],
            'to' => $phone,
            'message' => $message,
        ];

        if (!empty($config['sender_id'])) {
            $payload['from'] = $config['sender_id'];
        }

        $response = Http::asForm()
            ->withHeaders([
                'Accept' => 'application/json',
                'apiKey' => (string) $config['api_key'],
            ])
            ->timeout(15)
            ->retry(2, 500)
            ->post($endpoint, $payload);

        if ($response->successful()) {
            return [
                'success' => true,
                'status' => 'sent',
                'provider' => $this->id(),
                'provider_response' => $response->body(),
            ];
        }

        return [
            'success' => false,
            'status' => 'failed',
            'provider' => $this->id(),
            'provider_response' => sprintf('HTTP %s: %s', $response->status(), $response->body()),
        ];
    }
}
