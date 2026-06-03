<?php

namespace App\Billing\Support;

use App\Billing\Contracts\BillingProviderRegistry as BillingProviderRegistryContract;
use App\Billing\Providers\ProviderDefinition;
use App\Billing\Repositories\BillingConfigurationRepository;
use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\Platform;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class LegacyBillingConfigProjector
{
    public function __construct(
        private readonly BillingConfigurationRepository $configurationRepository,
        private readonly BillingProviderRegistryContract $providerRegistry
    ) {
    }

    /**
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    public function projectWalletSettings(Platform $platform, array $fallback = []): array
    {
        $projected = $fallback;
        $walletRule = $this->configurationRepository->walletRuleForMarket((int) $platform->id);

        if ($walletRule !== null) {
            $primaryCurrency = strtoupper(trim((string) ($walletRule->currency_code ?: $platform->primaryCurrency())));
            $projected['enabled'] = (bool) $walletRule->enabled;

            if (trim((string) $walletRule->currency_code) !== '') {
                $projected['currency_code'] = $primaryCurrency;
            }

            $supportedCurrencies = collect($walletRule->supported_currencies_json ?? $platform->supportedCurrencies())
                ->map(fn ($value) => strtoupper(trim((string) $value)))
                ->filter()
                ->unique()
                ->values()
                ->all();
            if ($supportedCurrencies !== []) {
                $projected['supported_currencies'] = $supportedCurrencies;
            }

            $presets = $this->normalizeMoneyValues($walletRule->topup_preset_json ?? []);
            if ($presets !== []) {
                $projected['topup_presets'] = $presets;
                $projected['topup_presets_by_currency'] = array_merge(
                    is_array($projected['topup_presets_by_currency'] ?? null) ? $projected['topup_presets_by_currency'] : [],
                    [$primaryCurrency => $presets]
                );
            }

            $presetsByCurrency = collect($walletRule->topup_preset_by_currency_json ?? [])
                ->mapWithKeys(fn ($values, $currency) => is_array($values)
                    ? [strtoupper(trim((string) $currency)) => $this->normalizeMoneyValues($values)]
                    : [])
                ->filter(fn ($values) => $values !== [])
                ->all();
            if ($presetsByCurrency !== []) {
                $projected['topup_presets_by_currency'] = $presetsByCurrency;
            }

            $minSingleTopup = data_get($walletRule->limit_json, 'min_single_topup');
            if ($this->filled($minSingleTopup)) {
                $projected['min_single_topup'] = $this->formatMoneyString($minSingleTopup, $projected['min_single_topup'] ?? '100.00');
            }

            $maxSingleTopup = data_get($walletRule->limit_json, 'max_single_topup');
            if ($this->filled($maxSingleTopup)) {
                $projected['max_single_topup'] = $this->formatMoneyString($maxSingleTopup, $projected['max_single_topup'] ?? '50000.00');
            }

            $maxWalletBalance = data_get($walletRule->limit_json, 'max_wallet_balance');
            if ($this->filled($maxWalletBalance)) {
                $projected['max_wallet_balance'] = $this->formatMoneyString($maxWalletBalance, $projected['max_wallet_balance'] ?? '200000.00');
            }

            if (isset($projected['min_single_topup']) || isset($projected['max_single_topup']) || isset($projected['max_wallet_balance'])) {
                $projected['limits_by_currency'] = array_merge(
                    is_array($projected['limits_by_currency'] ?? null) ? $projected['limits_by_currency'] : [],
                    [
                        $primaryCurrency => array_filter([
                            'min_single_topup' => $projected['min_single_topup'] ?? null,
                            'max_single_topup' => $projected['max_single_topup'] ?? null,
                            'max_wallet_balance' => $projected['max_wallet_balance'] ?? null,
                        ], static fn ($value) => $value !== null && $value !== ''),
                    ]
                );
            }

            $limitsByCurrency = collect($walletRule->limit_by_currency_json ?? [])
                ->mapWithKeys(function ($values, $currency) use ($projected) {
                    if (!is_array($values)) {
                        return [];
                    }

                    $normalized = [];
                    foreach (['min_single_topup', 'max_single_topup', 'max_wallet_balance'] as $key) {
                        if ($this->filled($values[$key] ?? null)) {
                            $normalized[$key] = $this->formatMoneyString(
                                $values[$key],
                                $projected[$key] ?? match ($key) {
                                    'min_single_topup' => '100.00',
                                    'max_single_topup' => '50000.00',
                                    default => '200000.00',
                                }
                            );
                        }
                    }

                    return $normalized === []
                        ? []
                        : [strtoupper(trim((string) $currency)) => $normalized];
                })
                ->all();
            if ($limitsByCurrency !== []) {
                $projected['limits_by_currency'] = $limitsByCurrency;
            }

            $allowCombined = data_get($walletRule->ui_json, 'allow_combined_topup_subscribe');
            if ($allowCombined !== null) {
                $projected['allow_combined_topup_subscribe'] = (bool) $allowCombined;
            }

            $showRefresh = data_get($walletRule->ui_json, 'show_refresh_button');
            if ($showRefresh !== null) {
                $projected['show_refresh_button'] = (bool) $showRefresh;
            }

            $recentTransactionsLimit = data_get($walletRule->ui_json, 'recent_transactions_limit');
            if ($recentTransactionsLimit !== null) {
                $projected['recent_transactions_limit'] = min(50, max(1, (int) $recentTransactionsLimit));
            }
        }

        $bindings = $this->configurationRepository->activeBindingsForMarket((int) $platform->id, BillingSurface::WalletFunding->value);
        foreach ($bindings as $binding) {
            $legacyKey = $this->legacyWalletKeyForBinding($binding);
            if ($legacyKey === null) {
                continue;
            }

            $projected['providers'][$legacyKey] = array_merge(
                is_array($projected['providers'][$legacyKey] ?? null) ? $projected['providers'][$legacyKey] : [],
                ['enabled' => true]
            );

            $minAmount = $this->bindingRestrictionValue($binding, 'min_amount');
            if ($this->filled($minAmount)) {
                $projected['providers'][$legacyKey]['min_amount'] = $this->formatMoneyString(
                    $minAmount,
                    data_get($projected, "providers.{$legacyKey}.min_amount", '100.00')
                );
            }

            $maxAmount = $this->bindingRestrictionValue($binding, 'max_amount');
            if ($this->filled($maxAmount)) {
                $projected['providers'][$legacyKey]['max_amount'] = $this->formatMoneyString(
                    $maxAmount,
                    data_get($projected, "providers.{$legacyKey}.max_amount", '150000.00')
                );
            }
        }

        $modeOverride = $this->projectModeOverride($bindings, $projected['mode_override'] ?? null);
        if ($modeOverride !== null) {
            $projected['mode_override'] = $modeOverride;
        }

        return $projected;
    }

    /**
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    public function projectPlatformCredentials(Platform $platform, array $fallback = []): array
    {
        $projected = $fallback;
        $profiles = $this->configurationRepository->providerProfilesForMarket((int) $platform->id);

        foreach ($profiles as $profile) {
            if (!(bool) $profile->active) {
                continue;
            }

            $legacyKey = $this->legacyWalletKeyForProviderType($profile->provider_type_key);
            if ($legacyKey === null) {
                continue;
            }

            $environment = $this->normalizeEnvironment($profile->environment);

            match ($legacyKey) {
                'pesapal' => $this->overlayPesapalCredentials($projected, $profile, $environment),
                'paystack' => $this->overlayPaystackCredentials($projected, $profile, $environment),
                'mpesa_stk' => $this->overlayMpesaCredentials($projected, $profile, $environment),
                default => null,
            };
        }

        return $projected;
    }

    /**
     * @param  array<string, mixed>|null  $fallback
     * @return array<string, mixed>|null
     */
    public function projectPaymentLinkProviders(Platform $platform, ?array $fallback = null): ?array
    {
        $projected = $this->normalizePaymentLinkProviders($fallback);
        $projected = $this->overlayProjectedPaymentLinkBindings(
            $platform,
            $projected,
            BillingSurface::ProxyHostedCheckout
        );
        $projected = $this->overlayProjectedPaymentLinkBindings(
            $platform,
            $projected,
            BillingSurface::SubscriptionLink
        );

        return $projected['providers'] === [] ? null : $projected;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<string, mixed>
     */
    private function normalizePaymentLinkProviders(?array $payload): array
    {
        return [
            'active_provider' => trim((string) ($payload['active_provider'] ?? '')),
            'providers' => is_array($payload['providers'] ?? null) ? $payload['providers'] : [],
        ];
    }

    private function projectModeOverride(iterable $bindings, mixed $fallback): ?string
    {
        $environments = [];

        foreach ($bindings as $binding) {
            $environment = $this->normalizeEnvironment((string) ($binding->providerProfile?->environment ?? ''));
            $environments[$environment] = true;
        }

        if ($environments === []) {
            return $this->normalizeNullableEnvironment($fallback);
        }

        if (count($environments) === 1) {
            return (string) array_key_first($environments);
        }

        return $this->normalizeNullableEnvironment($fallback);
    }

    private function legacyWalletKeyForBinding(BillingMarketProviderBinding $binding): ?string
    {
        return $this->legacyWalletKeyForProviderType((string) ($binding->providerProfile?->provider_type_key ?? ''));
    }

    private function legacyWalletKeyForProviderType(string $providerTypeKey): ?string
    {
        $definition = $this->providerRegistry->find($providerTypeKey)?->definition();
        if ($definition === null || !(bool) $definition->meta('legacy_wallet_selectable', false)) {
            return null;
        }

        return $definition->key;
    }

    private function proxyProviderKey(BillingProviderProfile $profile, BillingMarketProviderBinding $binding, array $existingKeys): string
    {
        $candidate = Str::snake($profile->provider_type_key . '_checkout');
        if (!in_array($candidate, $existingKeys, true)) {
            return $candidate;
        }

        return $candidate . '_' . $binding->id;
    }

    private function bindingRestrictionValue(BillingMarketProviderBinding $binding, string $key): mixed
    {
        return data_get($binding->restriction_json, $key, data_get($binding->restriction_json, "limits.{$key}"));
    }

    private function bindingFxEnabled(BillingMarketProviderBinding $binding): ?bool
    {
        $enabled = data_get($binding->restriction_json, 'self_checkout_fx_enabled', data_get($binding->restriction_json, 'self_checkout_fx.enabled'));

        return $enabled === null ? null : (bool) $enabled;
    }

    /**
     * @param  array<string, mixed>  $projected
     * @return array<string, mixed>
     */
    private function overlayProjectedPaymentLinkBindings(
        Platform $platform,
        array $projected,
        BillingSurface $surface
    ): array {
        $bindings = $this->configurationRepository->activeBindingsForMarket((int) $platform->id, $surface->value);
        $routingRule = $this->configurationRepository->routingRuleForMarket((int) $platform->id, $surface->value);
        $bindingKeys = [];

        foreach ($bindings as $binding) {
            $profile = $binding->providerProfile;
            $definition = $profile ? $this->providerRegistry->find($profile->provider_type_key)?->definition() : null;

            if ($profile === null || $definition === null || !$this->supportsProjectedPaymentLinkSurface($definition, $surface)) {
                continue;
            }

            $providerKey = $this->proxyProviderKey($profile, $binding, array_values($bindingKeys));
            $bindingKeys[(int) $binding->id] = $providerKey;

            $projected['providers'][$providerKey] = array_filter([
                'label' => trim((string) ($profile->profile_name ?: $definition->label . ' Checkout')),
                'mode' => 'proxy_hosted_checkout',
                'enabled' => (bool) $binding->enabled,
                'wallet_provider_key' => $definition->key,
                'environment' => $this->normalizeEnvironment($profile->environment),
                'provider_profile_id' => (int) $profile->id,
                'chosen_binding_id' => (int) $binding->id,
                'billing_surface' => $surface->value,
                'execution_mode' => trim((string) ($binding->execution_mode ?: 'direct')) ?: 'direct',
                'operator_enabled' => (bool) $binding->operator_enabled,
                'self_service_enabled' => (bool) $binding->self_service_enabled,
                'self_checkout_fx_enabled' => $this->bindingFxEnabled($binding),
                'self_checkout_fx_currency' => $this->bindingRestrictionValue($binding, 'self_checkout_fx_currency'),
                'self_checkout_fx_rate' => $this->bindingRestrictionValue($binding, 'self_checkout_fx_rate'),
            ], static fn ($value) => $value !== null);
        }

        if ($routingRule !== null && isset($bindingKeys[(int) $routingRule->primary_binding_id])) {
            $projected['active_provider'] = $bindingKeys[(int) $routingRule->primary_binding_id];
        } elseif (($projected['active_provider'] ?? '') === '' && $bindingKeys !== []) {
            $projected['active_provider'] = reset($bindingKeys) ?: '';
        }

        return $projected;
    }

    private function supportsProjectedPaymentLinkSurface(ProviderDefinition $definition, BillingSurface $surface): bool
    {
        if ($surface === BillingSurface::ProxyHostedCheckout) {
            return $definition->supportsSurface(BillingSurface::ProxyHostedCheckout);
        }

        return $surface === BillingSurface::SubscriptionLink
            && (
                $definition->supportsSurface(BillingSurface::ProxyHostedCheckout)
                || $definition->key === 'pawapay'
            );
    }

    private function overlayPesapalCredentials(array &$projected, BillingProviderProfile $profile, string $environment): void
    {
        $consumerKey = $this->profileValue($profile, 'consumer_key');
        $consumerSecret = $this->profileValue($profile, 'consumer_secret');
        $ipnId = $this->profileValue($profile, 'ipn_id');

        if ($this->filled($consumerKey)) {
            $projected['pesapal'][$environment]['consumer_key_encrypted'] = Crypt::encryptString((string) $consumerKey);
        }

        if ($this->filled($consumerSecret)) {
            $projected['pesapal'][$environment]['consumer_secret_encrypted'] = Crypt::encryptString((string) $consumerSecret);
        }

        if ($this->filled($ipnId)) {
            $projected['pesapal'][$environment]['ipn_id'] = trim((string) $ipnId);
        }
    }

    private function overlayPaystackCredentials(array &$projected, BillingProviderProfile $profile, string $environment): void
    {
        $publicKey = $this->profileValue($profile, 'public_key');
        $secretKey = $this->profileValue($profile, 'secret_key');

        if ($this->filled($publicKey)) {
            $projected['paystack'][$environment]['public_key_encrypted'] = Crypt::encryptString((string) $publicKey);
        }

        if ($this->filled($secretKey)) {
            $projected['paystack'][$environment]['secret_key_encrypted'] = Crypt::encryptString((string) $secretKey);
        }
    }

    private function overlayMpesaCredentials(array &$projected, BillingProviderProfile $profile, string $environment): void
    {
        $transport = $this->profileValue($profile, 'transport');
        if ($this->filled($transport) && in_array((string) $transport, ['django_proxy', 'direct_provider'], true)) {
            $projected['mpesa_stk'][$environment]['transport'] = (string) $transport;
        }

        foreach (['payment_service_base_url', 'organization_code', 'callback_base_url'] as $key) {
            $value = $this->profileValue($profile, $key);
            if ($this->filled($value)) {
                $projected['mpesa_stk'][$environment][$key] = trim((string) $value);
            }
        }
    }

    private function profileValue(BillingProviderProfile $profile, string $key): mixed
    {
        return data_get($profile->secrets_json, $key, data_get($profile->config_json, $key));
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function normalizeMoneyValues(array $values): array
    {
        return collect($values)
            ->map(fn ($value) => $this->formatMoneyString($value, null))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function formatMoneyString(mixed $value, ?string $fallback): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return $fallback;
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeEnvironment(?string $environment): string
    {
        $candidate = strtolower(trim((string) $environment));

        return in_array($candidate, ['sandbox', 'production'], true) ? $candidate : 'sandbox';
    }

    private function normalizeNullableEnvironment(mixed $environment): ?string
    {
        $candidate = strtolower(trim((string) $environment));

        return in_array($candidate, ['sandbox', 'production'], true) ? $candidate : null;
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }
}
