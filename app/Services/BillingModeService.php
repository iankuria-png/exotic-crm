<?php

namespace App\Services;

use App\Models\Platform;
use InvalidArgumentException;

class BillingModeService
{
    public function __construct(
        private readonly WalletSettingsService $walletSettingsService
    ) {
    }

    public function walletContext(Platform $platform): array
    {
        $system = $this->walletSettingsService->currentSystemConfig(masked: false);
        $wallet = $this->walletSettingsService->runtimePlatformConfig($platform);
        $environment = $this->resolveEnvironment((string) ($wallet['effective_mode'] ?? 'disabled'));

        return [
            'system' => $system,
            'wallet' => $wallet,
            'environment' => $environment,
            'mode' => $wallet['effective_mode'] ?? 'disabled',
        ];
    }

    public function assertWalletAvailable(Platform $platform): array
    {
        $context = $this->walletContext($platform);
        if (($context['mode'] ?? 'disabled') === 'disabled') {
            throw new InvalidArgumentException('Wallet billing is disabled for this market.');
        }

        return $context;
    }

    public function providerContext(
        Platform $platform,
        string $provider,
        bool $requireEnabled = true,
        ?string $environmentOverride = null
    ): array
    {
        $normalizedProvider = strtolower(trim($provider));
        if (!in_array($normalizedProvider, WalletSettingsService::PROVIDERS, true)) {
            throw new InvalidArgumentException('Unsupported wallet billing provider.');
        }

        $context = $requireEnabled
            ? $this->assertWalletAvailable($platform)
            : $this->walletContext($platform);
        $wallet = $context['wallet'];
        $environment = $this->resolveProviderEnvironment((string) ($context['environment'] ?? 'sandbox'), $environmentOverride);
        $providerConfig = data_get($wallet, "providers.{$normalizedProvider}", []);
        $providerCredentials = data_get($wallet, "credentials.{$normalizedProvider}.{$environment}", []);

        if ($requireEnabled && !(bool) ($providerConfig['enabled'] ?? false)) {
            throw new InvalidArgumentException('Selected provider is disabled for this market.');
        }

        $this->assertCredentialsPresent($normalizedProvider, $providerCredentials);

        return array_merge($context, [
            'environment' => $environment,
            'provider' => $normalizedProvider,
            'provider_config' => is_array($providerConfig) ? $providerConfig : [],
            'provider_credentials' => is_array($providerCredentials) ? $providerCredentials : [],
        ]);
    }

    public function browserBaseUrl(Platform $platform, ?string $environment = null): string
    {
        $context = $this->walletContext($platform);
        $environment = $environment ?: $context['environment'];
        $billingDomain = trim((string) data_get($context, "system.billing_domains.{$environment}", ''));

        if ($billingDomain !== '') {
            return rtrim($billingDomain, '/');
        }

        return rtrim((string) config('app.url', url('/')), '/');
    }

    public function buildAbsoluteUrl(Platform $platform, string $path, array $query = [], ?string $environment = null): string
    {
        $baseUrl = $this->browserBaseUrl($platform, $environment);
        $path = '/' . ltrim($path, '/');
        $url = $baseUrl . $path;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    private function assertCredentialsPresent(string $provider, array $credentials): void
    {
        if ($provider === 'paystack') {
            if (trim((string) ($credentials['public_key'] ?? '')) === '' || trim((string) ($credentials['secret_key'] ?? '')) === '') {
                throw new InvalidArgumentException('Paystack credentials are incomplete for the active environment.');
            }

            return;
        }

        if ($provider === 'pesapal') {
            if (
                trim((string) ($credentials['consumer_key'] ?? '')) === ''
                || trim((string) ($credentials['consumer_secret'] ?? '')) === ''
                || trim((string) ($credentials['ipn_id'] ?? '')) === ''
            ) {
                throw new InvalidArgumentException('Pesapal credentials are incomplete for the active environment.');
            }

            return;
        }

        if ($provider === 'mpesa_stk') {
            $transport = trim((string) ($credentials['transport'] ?? 'django_proxy'));
            if ($transport === 'direct_provider') {
                if (
                    trim((string) config('services.kopokopo.base_url', '')) === ''
                    || trim((string) config('services.kopokopo.client_id', '')) === ''
                    || trim((string) config('services.kopokopo.client_secret', '')) === ''
                    || trim((string) config('services.kopokopo.api_key', '')) === ''
                    || trim((string) config('services.kopokopo.till_number', '')) === ''
                ) {
                    throw new InvalidArgumentException('Direct KopoKopo configuration is incomplete.');
                }

                return;
            }

            if (
                trim((string) ($credentials['payment_service_base_url'] ?? '')) === ''
                || trim((string) ($credentials['organization_code'] ?? '')) === ''
            ) {
                throw new InvalidArgumentException('M-Pesa STK proxy configuration is incomplete for the active environment.');
            }
        }
    }

    private function resolveEnvironment(string $mode): string
    {
        return $mode === 'production' ? 'production' : 'sandbox';
    }

    private function resolveProviderEnvironment(string $defaultEnvironment, ?string $environmentOverride): string
    {
        $candidate = strtolower(trim((string) $environmentOverride));

        if (in_array($candidate, WalletSettingsService::ENVIRONMENTS, true)) {
            return $candidate;
        }

        return $this->resolveEnvironment($defaultEnvironment);
    }
}
