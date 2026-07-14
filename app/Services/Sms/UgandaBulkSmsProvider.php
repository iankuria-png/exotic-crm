<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

/**
 * BlueSMS Uganda — SMSLive247-family HTTP panel.
 * GET {base_url}?user=&password=&reciever=&sender=&message=. Success body starts with "1701".
 * NOTE: "reciever" is the gateway's own (mis)spelling, not a typo. Exact parameters could not
 * be confirmed from public docs; verify with a live Test Dispatch. The default base URL is
 * plain http:// because the vendor does not offer TLS on this endpoint.
 */
class UgandaBulkSmsProvider implements SmsProviderInterface
{
    public function id(): string
    {
        return 'uganda_bulk_sms';
    }

    public function label(): string
    {
        return 'Bulk SMS (Uganda)';
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'base_url', 'label' => 'Gateway URL', 'type' => 'url', 'required' => true, 'default' => 'http://bluesmsuganda.com/api-sub.php'],
            ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
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
                'provider_response' => 'Uganda Bulk SMS credentials are incomplete.',
            ];
        }

        $baseUrl = trim((string) $config['base_url']);
        $successCode = trim((string) ($config['success_code'] ?? '1701'));

        $response = Http::timeout(20)
            ->retry(2, 500)
            ->get($baseUrl, [
                'user' => (string) $config['username'],
                'password' => (string) $config['password'],
                'reciever' => $phone,
                'sender' => (string) $config['sender_id'],
                'message' => $message,
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
