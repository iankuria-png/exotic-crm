<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function sendSmsToClient(Client $client, string $message, array $context = []): array
    {
        return $this->sendSms($client->phone_normalized, $message, array_merge([
            'client_id' => $client->id,
            'platform_id' => $client->platform_id,
        ], $context));
    }

    public function sendSms(?string $phone, string $message, array $context = []): array
    {
        $prefix = (string) ($context['phone_prefix'] ?? config('services.sms.default_prefix', '254'));
        $normalizedPhone = $this->normalizePhone($phone, $prefix);

        if (!$normalizedPhone) {
            return [
                'success' => false,
                'status' => 'failed',
                'phone' => null,
                'provider_response' => 'Missing or invalid phone number',
            ];
        }

        $gatewayUrl = (string) config('services.sms.gateway_url');
        $orgCode = (string) config('services.sms.org_code', '76');
        $enabled = (bool) config('services.sms.enabled', false);

        if (!$enabled) {
            Log::info('SMS dispatch skipped: SMS disabled in configuration.', [
                'phone' => $normalizedPhone,
                'context' => $context,
            ]);

            return [
                'success' => true,
                'status' => 'disabled',
                'phone' => $normalizedPhone,
                'provider_response' => 'SMS dispatch disabled (SMS_ENABLED=false)',
            ];
        }

        if ($gatewayUrl === '') {
            return [
                'success' => false,
                'status' => 'failed',
                'phone' => $normalizedPhone,
                'provider_response' => 'SMS gateway URL is not configured',
            ];
        }

        try {
            $response = Http::timeout(15)
                ->retry(2, 500)
                ->post($gatewayUrl, [
                    'Phonenumber' => $normalizedPhone,
                    'OrgCode' => $orgCode,
                    'Message' => $message,
                ]);

            if ($response->successful()) {
                return [
                    'success' => true,
                    'status' => 'sent',
                    'phone' => $normalizedPhone,
                    'provider_response' => $response->body(),
                ];
            }

            return [
                'success' => false,
                'status' => 'failed',
                'phone' => $normalizedPhone,
                'provider_response' => sprintf('HTTP %s: %s', $response->status(), $response->body()),
            ];
        } catch (\Throwable $exception) {
            Log::error('SMS dispatch failed', [
                'phone' => $normalizedPhone,
                'context' => $context,
                'error' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 'failed',
                'phone' => $normalizedPhone,
                'provider_response' => $exception->getMessage(),
            ];
        }
    }

    private function normalizePhone(?string $phone, string $prefix = '254'): ?string
    {
        if (!$phone) {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', $phone);
        $normalized = ltrim((string) $normalized, '+');

        if (str_starts_with($normalized, '0')) {
            $normalized = $prefix . substr($normalized, 1);
        }

        if (!preg_match('/^\d{10,15}$/', $normalized)) {
            return null;
        }

        return $normalized;
    }
}

