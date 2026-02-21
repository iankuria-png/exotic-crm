<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

class LegacyGatewaySmsProvider implements SmsProviderInterface
{
    public function id(): string
    {
        return 'legacy_gateway';
    }

    public function configured(array $config): bool
    {
        return !empty($config['gateway_url']) && !empty($config['org_code']);
    }

    public function send(string $phone, string $message, array $config, array $context = []): array
    {
        if (!$this->configured($config)) {
            return [
                'success' => false,
                'status' => 'failed',
                'provider' => $this->id(),
                'provider_response' => 'Legacy gateway credentials are incomplete.',
            ];
        }

        $response = Http::timeout(15)
            ->retry(2, 500)
            ->post($config['gateway_url'], [
                'Phonenumber' => $phone,
                'OrgCode' => $config['org_code'],
                'Message' => $message,
            ]);

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
