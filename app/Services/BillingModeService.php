<?php

namespace App\Services;

use App\Billing\Contracts\BillingProviderRegistry as BillingProviderRegistryContract;
use App\Billing\Repositories\BillingConfigurationRepository;
use App\Billing\Support\BillingSurface;
use App\Billing\Support\KopoKopoRuntimeResolver;
use App\Models\BillingProviderProfile;
use App\Models\Platform;
use InvalidArgumentException;

class BillingModeService
{
    public function __construct(
        private readonly WalletSettingsService $walletSettingsService,
        private readonly BillingProviderRegistryContract $providerRegistry,
        private readonly BillingConfigurationRepository $billingConfigurationRepository,
        private readonly KopokopoConfigService $kopokopoConfigService,
        private readonly KopoKopoRuntimeResolver $kopokopoRuntimeResolver
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
        ?string $environmentOverride = null,
        ?string $surface = null
    ): array
    {
        $normalizedProvider = strtolower(trim($provider));
        $providerAdapter = $this->providerRegistry->find($normalizedProvider);
        $providerDefinition = $providerAdapter?->definition();
        $runtimeProvider = $this->runtimeProviderKey($normalizedProvider);
        $legacyWalletProviders = $this->providerRegistry->legacyWalletProviderKeys();

        $resolvedSurface = $surface ? BillingSurface::from($surface) : BillingSurface::WalletFunding;

        if (!$providerDefinition || !$providerDefinition->capabilities->supportsSurface($resolvedSurface)) {
            throw new InvalidArgumentException("Unsupported " . $resolvedSurface->value . " billing provider.");
        }

        $context = $requireEnabled
            ? $this->assertWalletAvailable($platform)
            : $this->walletContext($platform);
        $wallet = $context['wallet'];
        $environment = $this->resolveProviderEnvironment((string) ($context['environment'] ?? 'sandbox'), $environmentOverride);
        $providerConfig = data_get($wallet, "providers.{$runtimeProvider}", []);
        $providerCredentials = data_get($wallet, "credentials.{$runtimeProvider}.{$environment}", []);
        $resolvedDirectConfig = null;
        $resolvedBinding = null;
        $resolvedProfile = null;
        $resolvedFrom = null;
        $resolvedProfileCredentials = null;

        if (!in_array($normalizedProvider, $legacyWalletProviders, true) && $runtimeProvider === $normalizedProvider) {
            $resolved = $this->resolveProfileBackedProvider($platform, $normalizedProvider, $environment, $resolvedSurface->value);
            $resolvedBinding = $resolved['binding'];
            $resolvedProfile = $resolved['profile'];
            $resolvedProfileCredentials = $resolved['credentials'];
            $resolvedFrom = $resolved['resolved_from'];
            $providerConfig = array_merge(
                is_array($providerConfig) ? $providerConfig : [],
                ['enabled' => $resolvedBinding !== null
                    ? true
                    : (bool) data_get($providerConfig, 'enabled', false)]
            );
            if (!empty($resolvedProfileCredentials)) {
                $providerCredentials = $resolvedProfileCredentials;
            }
        }

        if (
            $normalizedProvider === 'kopokopo'
            && strtolower(trim((string) ($providerCredentials['transport'] ?? 'django_proxy'))) === 'direct_provider'
        ) {
            $resolved = $this->kopokopoRuntimeResolver->resolveWalletFundingConfig($platform, $environment);
            $resolvedDirectConfig = $resolved['config'];
            $resolvedBinding = $resolved['binding'];
            $resolvedProfile = $resolved['profile'];
            $resolvedFrom = $resolved['resolved_from'];
        }

        if ($requireEnabled && !(bool) ($providerConfig['enabled'] ?? false)) {
            throw new InvalidArgumentException('Selected provider is disabled for this market.');
        }

        $this->assertCredentialsPresent($runtimeProvider, $providerCredentials, $normalizedProvider, $resolvedDirectConfig);

        return array_merge($context, [
            'environment' => $environment,
            'provider' => $normalizedProvider,
            'provider_runtime_key' => $runtimeProvider,
            'provider_definition' => $providerDefinition,
            'provider_config' => is_array($providerConfig) ? $providerConfig : [],
            'provider_credentials' => is_array($providerCredentials) ? $providerCredentials : [],
            'provider_direct_config' => is_array($resolvedDirectConfig) ? $resolvedDirectConfig : null,
            'provider_profile_id' => $resolvedProfile?->id,
            'chosen_binding_id' => $resolvedBinding?->id,
            'provider_resolved_from' => $resolvedFrom,
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

    public function profileBackedProviderContext(
        Platform $platform,
        BillingProviderProfile $profile,
        ?int $chosenBindingId = null,
        bool $requireEnabled = false,
        ?string $environmentOverride = null
    ): array {
        $provider = strtolower(trim((string) $profile->provider_type_key));
        $providerAdapter = $this->providerRegistry->find($provider);
        $providerDefinition = $providerAdapter?->definition();

        if (!$providerDefinition || !$providerDefinition->capabilities->supportsSurface(BillingSurface::SubscriptionLink)) {
            throw new InvalidArgumentException('Unsupported subscription link billing provider.');
        }

        $context = $requireEnabled
            ? $this->assertWalletAvailable($platform)
            : $this->walletContext($platform);
        $environment = $this->resolveProviderEnvironment(
            $this->resolveEnvironment((string) ($profile->environment ?: data_get($context, 'environment', 'sandbox'))),
            $environmentOverride
        );
        $providerCredentials = array_merge((array) ($profile->config_json ?? []), (array) ($profile->secrets_json ?? []));

        $this->assertCredentialsPresent($provider, $providerCredentials, $provider);

        return array_merge($context, [
            'environment' => $environment,
            'provider' => $provider,
            'provider_runtime_key' => $provider,
            'provider_definition' => $providerDefinition,
            'provider_config' => ['enabled' => (bool) $profile->active],
            'provider_credentials' => $providerCredentials,
            'provider_direct_config' => null,
            'provider_profile_id' => $profile->id,
            'chosen_binding_id' => $chosenBindingId,
            'provider_resolved_from' => 'provider_profile',
        ]);
    }

    private function assertCredentialsPresent(
        string $provider,
        array $credentials,
        ?string $providerAlias = null,
        ?array $resolvedDirectConfig = null
    ): void
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

        if ($provider === 'pawapay') {
            if (trim((string) ($credentials['api_key'] ?? '')) === '') {
                throw new InvalidArgumentException('pawaPay credentials are incomplete for the active environment.');
            }

            return;
        }

        if ($provider === 'mpesa_stk') {
            $transport = trim((string) ($credentials['transport'] ?? 'django_proxy'));
            if ($transport === 'direct_provider') {
                $config = $providerAlias === 'kopokopo' && is_array($resolvedDirectConfig)
                    ? $resolvedDirectConfig
                    : null;

                if (!$this->kopokopoConfigService->credentialsReady($config)) {
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

    private function runtimeProviderKey(string $provider): string
    {
        return match ($provider) {
            'daraja' => 'mpesa_stk',
            'kopokopo' => 'mpesa_stk',
            default => $provider,
        };
    }

    /**
     * @return array{binding: mixed, profile: mixed, credentials: array<string, mixed>, resolved_from: string}
     */
    private function resolveProfileBackedProvider(Platform $platform, string $providerTypeKey, string $environment, ?string $surface = null): array
    {
        $surface = $surface ?: BillingSurface::WalletFunding->value;

        $binding = $this->billingConfigurationRepository->firstActiveBindingForProvider(
            (int) $platform->id,
            $surface,
            $providerTypeKey,
            $environment
        );

        $profile = $binding?->providerProfile;

        return [
            'binding' => $binding,
            'profile' => $profile,
            'credentials' => $profile
                ? array_merge((array) ($profile->config_json ?? []), (array) ($profile->secrets_json ?? []))
                : [],
            'resolved_from' => $profile ? 'provider_profile' : null,
        ];
    }
}
