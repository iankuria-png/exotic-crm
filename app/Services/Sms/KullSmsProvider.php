<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

/**
 * KullSMS (Nigeria) — SMSLive247-family HTTP panel.
 * GET {base_url}?username=&password=&message=&sender=&mobiles=. Success body starts with "1701".
 * NOTE: exact parameter spelling could not be confirmed from public docs; verify with a live
 * Test Dispatch. base_url / success_code are operator-editable in Settings for that reason.
 */
class KullSmsProvider implements SmsProviderInterface
{
    public function id(): string
    {
        return 'kullsms';
    }

    public function label(): string
    {
        return 'KullSMS (Nigeria)';
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'Gateway URL', 'type' => 'url', 'required' => true, 'default' => 'https://kullsms.com/customer/api/'],
            ['key' => 'username', 'label' => 'Username / Email Address', 'type' => 'text', 'required' => true],
            ['key' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'secret' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text', 'required' => true],
            ['key' => 'success_code', 'label' => 'Success Code', 'type' => 'text', 'required' => false, 'default' => '1701'],
        ];
    }

    public function configured(array $config): bool
    {
        return !empty($config['base_url'])
            && !empty($config['username'])
            && !empty($config['password'])
            && !empty($config['sender_id']);
    }

    public function send(string $phone, string $message, array $config, array $context = []): array
    {
        if (!$this->configured($config)) {
            return [
                'success' => false,
                'status' => 'failed',
                'provider' => $this->id(),
                'provider_response' => 'KullSMS credentials are incomplete.',
            ];
        }

        $baseUrl = trim((string) $config['base_url']);
        $successCode = trim((string) ($config['success_code'] ?? '1701'));

        $response = Http::timeout(20)
            ->retry(2, 500)
            ->get($baseUrl, [
                'username' => (string) $config['username'],
                'password' => (string) $config['password'],
                'message' => $message,
                'sender' => (string) $config['sender_id'],
                'mobiles' => $phone,
            ]);

        $body = trim($response->body());
        $success = $response->successful() && SmsSuccessCode::matches($body, $successCode);

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
