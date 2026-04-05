<?php
// app/Services/KopokopoService.php

namespace App\Services;

use Kopokopo\SDK\K2;
use Illuminate\Support\Facades\Log;

class KopokopoService
{
    protected $k2;

    public function __construct(
        private readonly KopokopoConfigService $configService
    ) {
        $this->k2 = null;
    }

    protected function client(array $configOverride = [])
    {
        if ($configOverride === [] && $this->k2 !== null) {
            return $this->k2;
        }

        $config = array_replace($this->configService->currentConfig(masked: false), $configOverride);
        $options = [
            'clientId' => $config['client_id'] ?? null,
            'clientSecret' => $config['client_secret'] ?? null,
            'apiKey' => $config['api_key'] ?? null,
            'baseUrl' => $config['base_url'] ?? null,
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
        try {
            $tokenService = $this->client($configOverride)->TokenService();
            $result = $tokenService->getToken();

            if ($result['status'] === 'success') {
                return $result['data']['accessToken'];
            }

            Log::error('Failed to get Kopokopo access token', ['response' => $result]);
            return null;
        } catch (\Exception $e) {
            Log::error('Kopokopo token error: ' . $e->getMessage());
            return null;
        }
    }

    public function initiateStkPush($phone, $amount, $callbackUrl, $metadata = [], array $configOverride = [])
    {
        $accessToken = $this->getAccessToken($configOverride);
        
        if (!$accessToken) {
            return [
                'status' => false,
                'error' => 'Failed to authenticate with payment gateway'
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
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Convert to 254 format if it starts with 0
        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }
        // Ensure it's in 254 format
        elseif (!str_starts_with($phone, '254')) {
            $phone = '254' . ltrim($phone, '254');
        }
        
        return $phone;
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
