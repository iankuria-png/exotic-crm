<?php
// app/Services/KopokopoService.php

namespace App\Services;

use Kopokopo\SDK\K2;
use Illuminate\Support\Facades\Log;

class KopokopoService
{
    protected $k2;

    private ?string $lastAuthError = null;

    public function __construct(
        private readonly KopokopoConfigService $configService
    ) {
        $this->k2 = null;
    }

    public function lastAuthError(): ?string
    {
        return $this->lastAuthError;
    }

    private function cleanCredential(?string $value): string
    {
        return trim((string) ($value ?? ''));
    }

    /**
     * Extract a non-sensitive description of a KopoKopo token failure.
     * Returns only the OAuth error code/description/HTTP status — never credential values.
     */
    private function describeTokenError(array $result): ?string
    {
        $data = $result['data'] ?? [];

        $error = trim((string) ($data['error'] ?? ''));
        $description = trim((string) ($data['errorDescription'] ?? $data['error_description'] ?? ''));
        $status = $data['httpStatus'] ?? $data['status'] ?? null;

        $parts = [];
        if ($error !== '') {
            $parts[] = $error;
        }
        if ($description !== '') {
            $parts[] = $description;
        }

        if ($parts === []) {
            return $status !== null ? "HTTP {$status}" : null;
        }

        $reason = implode(': ', $parts);

        if ($status !== null && $error === '') {
            $reason = "HTTP {$status} {$reason}";
        }

        return $reason;
    }

    protected function client(array $configOverride = [])
    {
        if ($configOverride === [] && $this->k2 !== null) {
            return $this->k2;
        }

        $config = array_replace($this->configService->currentConfig(masked: false), $configOverride);
        $options = [
            'clientId' => $this->cleanCredential($config['client_id'] ?? null),
            'clientSecret' => $this->cleanCredential($config['client_secret'] ?? null),
            'apiKey' => $this->cleanCredential($config['api_key'] ?? null),
            'baseUrl' => rtrim($this->cleanCredential($config['base_url'] ?? null), '/'),
        ];

        $requiredKeys = ['clientId', 'clientSecret', 'apiKey', 'baseUrl'];
        foreach ($requiredKeys as $key) {
            if (empty($options[$key])) {
                throw new \InvalidArgumentException("KopoKopo configuration is incomplete: missing {$key}.");
            }
        }

        $client = new K2($options);

        if ($configOverride === []) {
            $this->k2 = $client;
        }

        return $client;
    }

    public function getAccessToken(array $configOverride = [])
    {
        $this->lastAuthError = null;

        try {
            $tokenService = $this->client($configOverride)->TokenService();
            $result = $tokenService->getToken();

            if (($result['status'] ?? null) === 'success') {
                return $result['data']['accessToken'];
            }

            $this->lastAuthError = $this->describeTokenError(is_array($result) ? $result : []);
            Log::error('Failed to get Kopokopo access token', ['response' => $result]);
            return null;
        } catch (\Exception $e) {
            $this->lastAuthError = $e->getMessage();
            Log::error('Kopokopo token error: ' . $e->getMessage());
            return null;
        }
    }

    public function initiateStkPush($phone, $amount, $callbackUrl, $metadata = [], array $configOverride = [])
    {
        $accessToken = $this->getAccessToken($configOverride);

        if (!$accessToken) {
            $reason = $this->lastAuthError;

            return [
                'status' => false,
                'error' => 'KopoKopo authentication failed' . ($reason ? ': ' . $reason : '.')
            ];
        }

        try {
            $stkService = $this->client($configOverride)->StkService();
            $config = array_replace($this->configService->currentConfig(masked: false), $configOverride);
            
            $response = $stkService->initiateIncomingPayment([
                'paymentChannel' => 'M-PESA STK Push',
                'tillNumber' => $config['till_number'] ?? '',
                'firstName' => 'Customer', // You can customize or get from user profile
                'lastName' => 'User',
                'phoneNumber' => $this->normalizePhone($phone),
                'amount' => $amount,
                'currency' => 'KES',
                'email' => 'customer@example.com', // You can get from user profile
                'callbackUrl' => $callbackUrl,
                'accessToken' => $accessToken,
                'metadata' => $metadata,
            ]);

            return $response;
        } catch (\Exception $e) {
            Log::error('STK Push initiation error: ' . $e->getMessage());
            return [
                'status' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function currentConfig(bool $masked = true): array
    {
        return $this->configService->currentConfig(masked: $masked);
    }

    public function normalizePhone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', (string) $phone);

        // Convert to 254 format if it starts with 0
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }
        // Bare local number (e.g. 712345678) — prepend country code
        elseif (!str_starts_with($phone, '254')) {
            $phone = '254' . $phone;
        }

        // KopoKopo's SDK validates against /^\+254\d{9}$/ and rejects
        // anything without the leading "+" as "Invalid phone number format".
        return '+' . $phone;
    }

    public function handleWebhook($payload, $signature)
    {
        try {
            $webhooks = $this->client()->Webhooks();
            return $webhooks->webhookHandler($payload, $signature);
        } catch (\Exception $e) {
            Log::error('Webhook handling error: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
}
