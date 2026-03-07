<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;

class WalletPayloadService
{
    public function config(Platform $platform, array $context): array
    {
        $wallet = $context['wallet'];
        $system = $context['system'];

        $providers = collect($wallet['providers'] ?? [])
            ->filter(fn (array $provider) => (bool) ($provider['enabled'] ?? false))
            ->map(function (array $provider, string $providerKey) use ($wallet, $context) {
                $payload = [
                    'enabled' => true,
                    'min_amount' => $provider['min_amount'] ?? null,
                    'max_amount' => $provider['max_amount'] ?? null,
                ];

                if ($providerKey === 'mpesa_stk') {
                    $payload['transport'] = data_get($wallet, "credentials.mpesa_stk.{$context['environment']}.transport");
                }

                return $payload;
            })
            ->all();

        return [
            'market' => [
                'platform_id' => (int) $platform->id,
                'currency' => $wallet['currency_code'] ?? $platform->currency_code,
            ],
            'topup_presets' => $wallet['topup_presets'] ?? [],
            'providers' => $providers,
            'show_refresh_button' => (bool) ($wallet['show_refresh_button'] ?? false),
            'allow_combined_topup_subscribe' => (bool) ($wallet['allow_combined_topup_subscribe'] ?? false),
            'recent_transactions_limit' => (int) ($wallet['recent_transactions_limit'] ?? 10),
            'wallet_refresh_rate_limit_seconds' => (int) ($system['wallet_refresh_rate_limit_seconds'] ?? 15),
            'wallet_refresh_timeout_seconds' => (int) ($system['wallet_refresh_timeout_seconds'] ?? 15),
            'topup_poll_interval_seconds' => (int) ($system['topup_poll_interval_seconds'] ?? 10),
            'sandbox_badge' => ($context['mode'] ?? 'disabled') === 'sandbox',
            'business_name' => data_get($system, 'billing_branding.' . $context['environment'] . '.business_name'),
            'description' => data_get($system, 'billing_branding.' . $context['environment'] . '.description'),
        ];
    }

    public function configSync(Platform $platform, array $context, string $syncedAt): array
    {
        return [
            'platform_id' => (int) $platform->id,
            'mode' => (string) ($context['mode'] ?? 'disabled'),
            'synced_at' => $syncedAt,
            'config' => $this->config($platform, $context),
        ];
    }

    public function balanceSync(Client $client, array $summary, array $context, string $syncedAt): array
    {
        return [
            'platform_id' => (int) $client->platform_id,
            'wp_user_id' => (int) ($client->wp_user_id ?? 0),
            'wp_post_id' => (int) ($client->wp_post_id ?? 0),
            'balance' => $summary['balance'],
            'currency' => $summary['currency'],
            'mode' => (string) ($context['mode'] ?? 'disabled'),
            'refreshed_at' => $summary['refreshed_at'] ?? $syncedAt,
            'wallet_last_synced_at' => $syncedAt,
            'last_topup' => $summary['last_topup'] ?? null,
            'transactions' => $summary['transactions'] ?? [],
        ];
    }
}
