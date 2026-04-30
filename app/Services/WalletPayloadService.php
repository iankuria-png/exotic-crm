<?php

namespace App\Services;

use App\Billing\Repositories\BillingConfigurationRepository;
use App\Billing\Support\BillingSurface;
use App\Billing\Support\MarketBillingMethodPolicy;
use App\Models\Client;
use App\Models\Platform;

class WalletPayloadService
{
    public function __construct(
        private readonly MarketBillingMethodPolicy $marketBillingMethodPolicy,
        private readonly BillingConfigurationRepository $billingConfigurationRepository,
        private readonly SelfServiceIncentiveService $selfServiceIncentiveService
    ) {
    }

    public function config(Platform $platform, array $context): array
    {
        $wallet = $context['wallet'];
        $system = $context['system'];

        $providers = collect($wallet['providers'] ?? [])
            ->filter(function (array $provider, string $providerKey) use ($wallet, $context) {
                if (!(bool) ($provider['enabled'] ?? false)) {
                    return false;
                }

                if ($providerKey === 'mpesa_stk') {
                    return data_get($wallet, "credentials.mpesa_stk.{$context['environment']}.transport") === 'direct_provider';
                }

                return true;
            })
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

        // Merge in any profile-backed wallet_funding bindings (e.g. PawaPay) that
        // are marked self_service_enabled. These are not stored in legacy config so
        // they would otherwise be invisible to WordPress.
        $bindings = $this->billingConfigurationRepository->activeBindingsForMarket(
            (int) $platform->id,
            BillingSurface::WalletFunding->value
        );

        foreach ($bindings as $binding) {
            if (!(bool) ($binding->self_service_enabled ?? false)) {
                continue;
            }

            $providerKey = strtolower(trim((string) ($binding->providerProfile?->provider_type_key ?? '')));
            if ($providerKey === '' || isset($providers[$providerKey])) {
                continue;
            }

            $providers[$providerKey] = [
                'enabled' => true,
                'min_amount' => $binding->restriction_json['min_amount'] ?? null,
                'max_amount' => $binding->restriction_json['max_amount'] ?? null,
            ];
        }

        return [
            'market' => [
                'platform_id' => (int) $platform->id,
                'currency' => $wallet['currency_code'] ?? $platform->currency_code,
                'effective_currencies' => $platform->effectiveCurrencies(),
                'multi_currency_wallet_enabled' => $platform->isMultiCurrencyWalletEnabled(),
            ],
            'topup_presets' => $wallet['topup_presets'] ?? [],
            'topup_presets_by_currency' => $wallet['topup_presets_by_currency'] ?? [],
            'limits_by_currency' => $wallet['limits_by_currency'] ?? [],
            'supported_currencies' => $wallet['supported_currencies'] ?? $platform->effectiveCurrencies(),
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
            'billing_method_policy' => $this->marketBillingMethodPolicy->contract($platform),
            'self_service_incentive' => $this->selfServiceIncentiveService->resolveActiveIncentive((int) $platform->id),
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
            'balances' => $summary['balances'] ?? [],
            'mode' => (string) ($context['mode'] ?? 'disabled'),
            'refreshed_at' => $summary['refreshed_at'] ?? $syncedAt,
            'wallet_last_synced_at' => $syncedAt,
            'last_topup' => $summary['last_topup'] ?? null,
            'transactions' => $summary['transactions'] ?? [],
        ];
    }
}
