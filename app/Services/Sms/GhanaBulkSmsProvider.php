<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

/**
 * BulkSMS Ghana — https://bulksmsghana.com/developer/
 * GET {base_url}?key=&to=&msg=&sender_id=. The gateway answers either with a
 * plain status code ("1000") or a JSON envelope ({"success":true,"code":"1000"});
 * both are treated as success when the code matches success_code.
 */
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
            ['key' => 'base_url', 'label' => 'Gateway URL', 'type' => 'url', 'required' => true, 'default' => 'https://clientlogin.bulksmsgh.com/smsapi'],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text', 'required' => true],
            ['key' => 'success_code', 'label' => 'Success Code', 'type' => 'text', 'required' => false, 'default' => '1000'],
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
        $decoded = json_decode($body, true);

        // A JSON object means the gateway returned an envelope; a bare code like
        // "1000" decodes to an int (not an array) and takes the plain-text path.
        if (is_array($decoded)) {
            $actualCode = isset($decoded['code']) ? trim((string) $decoded['code']) : null;
            $flag = $decoded['success'] ?? $decoded['status'] ?? null;
            $flag = is_string($flag) ? strtolower($flag) : $flag;
            $flagOk = in_array($flag, [true, 1, '1', 'true', 'success'], true);

            $success = $response->successful()
                && ($actualCode !== null ? $actualCode === $successCode : $flagOk);
        } else {
            $success = $response->successful() && SmsSuccessCode::matches($body, $successCode);
            $actualCode = $success ? $successCode : null;
        }

        return [
            'success' => $success,
            'status' => $success ? 'sent' : 'failed',
            'provider' => $this->id(),
            'provider_response' => $body,
            'http_code' => $response->status(),
            'expected_success_code' => $successCode,
            'actual_success_code' => $actualCode,
        ];
    }
}
