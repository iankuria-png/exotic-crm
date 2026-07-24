<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;

class AfricasTalkingSmsProvider implements SmsProviderInterface, BalanceAwareSmsProvider
{
    public function id(): string
    {
        return 'africastalking';
    }

    /**
     * Africa's Talking exposes the account balance via the user data endpoint:
     * GET /version1/user?username=… → { UserData: { balance: "KES 1234.5000" } }.
     */
    public function fetchBalance(array $config): ?array
    {
        if (!$this->configured($config)) {
            return null;
        }

        $messagingEndpoint = (string) ($config['endpoint'] ?? 'https://api.africastalking.com/version1/messaging');
        $userEndpoint = preg_replace('#/messaging/?$#', '/user', $messagingEndpoint) ?: 'https://api.africastalking.com/version1/user';

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'apiKey' => (string) $config['api_key'],
            ])
                ->timeout(10)
                ->get($userEndpoint, ['username' => (string) $config['username']]);

            if (!$response->successful()) {
                return null;
            }

            $raw = trim((string) data_get($response->json(), 'UserData.balance', ''));
            if ($raw === '') {
                return null;
            }

            // e.g. "KES 1234.5000" → currency + amount.
            if (preg_match('/([A-Z]{3})\s*([\d.,]+)/', $raw, $m)) {
                return [
                    'amount' => (float) str_replace(',', '', $m[2]),
                    'currency' => $m[1],
                    'raw' => $raw,
                ];
            }

            return ['amount' => (float) preg_replace('/[^\d.]/', '', $raw), 'currency' => '', 'raw' => $raw];
        } catch (\Throwable) {
            return null;
        }
    }

    public function label(): string
    {
        return "Africa's Talking";
    }

    public function credentialFields(): array
    {
        return [
            ['key' => 'endpoint', 'label' => 'Endpoint', 'type' => 'url', 'required' => false, 'default' => 'https://api.africastalking.com/version1/messaging'],
            ['key' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true],
            ['key' => 'api_key', 'label' => 'API Key', 'type' => 'password', 'required' => true, 'secret' => true],
            ['key' => 'sender_id', 'label' => 'Sender ID', 'type' => 'text', 'required' => false],
        ];
    }

    public function configured(array $config): bool
    {
        return !empty($config['username']) && !empty($config['api_key']);
    }

    public function send(string $phone, string $message, array $config, array $context = []): array
    {
        if (!$this->configured($config)) {
            return [
                'success' => false,
                'status' => 'failed',
                'provider' => $this->id(),
                'provider_response' => "Africa's Talking credentials are incomplete.",
            ];
        }

        $endpoint = $config['endpoint'] ?? 'https://api.africastalking.com/version1/messaging';
        $payload = [
            'username' => $config['username'],
            'to' => $phone,
            'message' => $message,
        ];

        if (!empty($config['sender_id'])) {
            $payload['from'] = $config['sender_id'];
        }

        $response = Http::asForm()
            ->withHeaders([
                'Accept' => 'application/json',
                'apiKey' => (string) $config['api_key'],
            ])
            ->timeout(15)
            ->retry(2, 500)
            ->post($endpoint, $payload);

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
