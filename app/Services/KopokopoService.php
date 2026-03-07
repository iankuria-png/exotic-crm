<?php
// app/Services/KopokopoService.php

namespace App\Services;

use Kopokopo\SDK\K2;
use Illuminate\Support\Facades\Log;

class KopokopoService
{
    protected $k2;

    public function __construct()
    {
        $this->k2 = null;
    }

    protected function client()
    {
        if ($this->k2 !== null) {
            return $this->k2;
        }

        $options = [
            'clientId' => config('services.kopokopo.client_id'),
            'clientSecret' => config('services.kopokopo.client_secret'),
            'apiKey' => config('services.kopokopo.api_key'),
            'baseUrl' => config('services.kopokopo.base_url'),
        ];

        $requiredKeys = ['clientId', 'clientSecret', 'apiKey', 'baseUrl'];
        foreach ($requiredKeys as $key) {
            if (empty($options[$key])) {
                throw new \InvalidArgumentException("KopoKopo configuration is incomplete: missing {$key}.");
            }
        }

        $this->k2 = new K2($options);

        return $this->k2;
    }

    public function getAccessToken()
    {
        try {
            $tokenService = $this->client()->TokenService();
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

    public function initiateStkPush($phone, $amount, $callbackUrl, $metadata = [])
    {
        $accessToken = $this->getAccessToken();
        
        if (!$accessToken) {
            return [
                'status' => false,
                'error' => 'Failed to authenticate with payment gateway'
            ];
        }

        try {
            $stkService = $this->client()->StkService();
            
            $response = $stkService->initiateIncomingPayment([
                'paymentChannel' => 'M-PESA STK Push',
                'tillNumber' => config('services.kopokopo.till_number'),
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
