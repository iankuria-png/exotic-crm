<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

/**
 * BulkSMS Ghana — https://bulksmsghana.com/developer/
 * GET {base_url}?key=&to=&msg=&sender_id=. The gateway answers either with a
 * plain status code ("1000") or a JSON envelope ({"success":true,"code":"1000"});
 * both are treated as success when the code matches success_code.
 */
class GhanaBulkSmsProvider implements SmsProviderInterface, BalanceAwareSmsProvider
{
    public function id(): string
    {
        return 'ghana_bulk_sms';
    }

    public function label(): string
    {
        return 'BulkSMS (Ghana)';
    }

    /**
     * BulkSMS Ghana balance: GET {host}/api/smsapibalance?key=API_KEY, returning
     * a plain-text amount (GHS). "1004" means an invalid key.
     * Docs: https://bulksmsghana.com/developer/
     */
    public function fetchBalance(array $config): ?array
    {
        if (empty($config['base_url']) || empty($config['api_key'])) {
            return null;
        }

        // The balance endpoint lives at the host root, not under the /smsapi
        // send path — derive scheme://host from the configured gateway URL.
        $parts = parse_url(trim((string) $config['base_url']));
        if (empty($parts['host'])) {
            return null;
        }
        $endpoint = ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . '/api/smsapibalance';

        try {
            $response = Http::timeout(10)->get($endpoint, ['key' => (string) $config['api_key']]);
            if (!$response->successful()) {
                return null;
            }

            $raw = trim($response->body());
            // Invalid-key / error codes rather than a balance.
            if ($raw === '' || $raw === '1004' || !is_numeric(str_replace(',', '', $raw))) {
                return null;
            }

            return [
                'amount' => (float) str_replace(',', '', $raw),
                'currency' => 'GHS',
                'raw' => $raw,
            ];
        } catch (\Throwable) {
            return null;
        }
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
