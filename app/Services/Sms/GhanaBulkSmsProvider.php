<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

class GhanaBulkSmsProvider implements SmsProviderInterface
{
    public function id(): string
    {
        return 'ghana_bulk_sms';
    }

    public function label(): string
    {
        return 'BulkSMS (Ghana)';
    }

    public function credentialFields(): array
    {
        return [
            [
                'key' => 'base_url',
                'label' => 'Gateway URL',
                'type' => 'url',
                'required' => true,
                'default' => 'https://clientlogin.bulksmsgh.com/smsapi',
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
            [
                'key' => 'success_code',
                'label' => 'Success Code',
                'type' => 'text',
                'required' => false,
                'default' => '1000',
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
                'provider_response' => 'BulkSMS Ghana credentials are incomplete.',
            ];
        }

        $baseUrl = trim((string) $config['base_url']);
        $successCode = trim((string) ($config['success_code'] ?? '1000'));

        $response = Http::timeout(20)
            ->retry(2, 500)
            ->get($baseUrl, [
                'key' => (string) $config['api_key'],
                'to' => $phone,
                'msg' => $message,
                'sender_id' => (string) $config['sender_id'],
            ]);

        $body = trim($response->body());

        $success = $response->successful()
            && (
                $body === $successCode
                || str_starts_with($body, $successCode . ' ')
                || str_starts_with($body, $successCode . ':')
                || str_starts_with($body, $successCode . '-')
            );

        return [
            'success' => $success,
            'status' => $success ? 'sent' : 'failed',
            'provider' => $this->id(),
            'provider_response' => $body,
            'http_code' => $response->status(),
            'expected_success_code' => $successCode,
        ];
    }
}
