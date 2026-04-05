<?php

namespace App\Billing\Support;

use App\Billing\Repositories\BillingConfigurationRepository;
use App\Models\Platform;
use App\Services\KopokopoConfigService;

class KopoKopoRuntimeResolver
{
    public function __construct(
        private readonly BillingConfigurationRepository $configurationRepository,
        private readonly KopokopoConfigService $kopokopoConfigService
    ) {
    }

    /**
     * @return array{binding: mixed, profile: mixed, config: array<string, mixed>, resolved_from: string}
     */
    public function resolveWalletFundingConfig(Platform $platform, string $environment): array
    {
        $binding = $this->configurationRepository->firstActiveBindingForProvider(
            (int) $platform->id,
            BillingSurface::WalletFunding->value,
            'kopokopo',
            $environment
        );

        $profile = $binding?->providerProfile;

        if ($profile) {
            return [
                'binding' => $binding,
                'profile' => $profile,
                'config' => array_merge(
                    (array) ($profile->config_json ?? []),
                    (array) ($profile->secrets_json ?? [])
                ),
                'resolved_from' => 'provider_profile',
            ];
        }

        return [
            'binding' => null,
            'profile' => null,
            'config' => $this->kopokopoConfigService->currentConfig(masked: false),
            'resolved_from' => 'legacy_config',
        ];
    }
}
