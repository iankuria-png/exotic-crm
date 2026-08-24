<?php

namespace App\Services;

use App\Models\ContactUnlockPricingRule;
use App\Models\Platform;
use Illuminate\Support\Collection;

class ContactUnlockPricingService
{
    public function __construct(
        private readonly FeatureSettingsService $featureSettings
    ) {
    }

    public function enabledForPlatform(Platform $platform): bool
    {
        if (! $this->globallyEnabled()) {
            return false;
        }

        $marketIds = $this->enabledMarketIds();
        return empty($marketIds) || in_array((int) $platform->id, $marketIds, true);
    }

    public function globallyEnabled(): bool
    {
        return (bool) $this->featureSettings->get(
            'contact_unlock.enabled',
            (bool) config('services.contact_unlock.enabled', false)
        );
    }

    public function enabledMarketIds(): array
    {
        $configured = $this->featureSettings->get(
            'contact_unlock.market_ids',
            array_map('intval', (array) config('services.contact_unlock.market_ids', []))
        );

        return array_values(array_unique(array_filter(array_map('intval', (array) $configured))));
    }

    public function sandboxOnly(): bool
    {
        return (bool) $this->featureSettings->get(
            'contact_unlock.sandbox_only',
            (bool) config('services.contact_unlock.sandbox_only', true)
        );
    }

    public function checkoutEnvironment(): ?string
    {
        return $this->sandboxOnly() ? 'sandbox' : null;
    }

    public function updateAvailability(bool $enabled, array $marketIds, ?bool $sandboxOnly = null, ?int $actorId = null): void
    {
        $this->featureSettings->set('contact_unlock.enabled', $enabled, $actorId);
        $this->featureSettings->set('contact_unlock.market_ids', array_values(array_unique(array_filter(array_map('intval', $marketIds)))), $actorId);

        if ($sandboxOnly !== null) {
            $this->featureSettings->set('contact_unlock.sandbox_only', $sandboxOnly, $actorId);
        }
    }

    public function activeRules(Platform $platform): Collection
    {
        if (! $this->enabledForPlatform($platform)) {
            return collect();
        }

        return ContactUnlockPricingRule::query()
            ->activeForPlatform((int) $platform->id)
            ->orderBy('amount')
            ->get();
    }

    public function findActiveRule(Platform $platform, int $ruleId): ContactUnlockPricingRule
    {
        return ContactUnlockPricingRule::query()
            ->activeForPlatform((int) $platform->id)
            ->whereKey($ruleId)
            ->firstOrFail();
    }

    public function providerOptions(Platform $platform): array
    {
        $providers = [];
        $country = strtolower((string) ($platform->country ?? ''));
        $currency = strtoupper((string) ($platform->currency_code ?? ''));

        if ($currency === 'KES' || str_contains($country, 'kenya')) {
            $providers[] = [
                'key' => 'kopokopo',
                'label' => 'M-Pesa prompt',
                'description' => 'Pay from the phone prompt',
                'rail' => 'mobile_money',
            ];
        }

        $providers[] = [
            'key' => 'pawapay',
            'label' => 'pawaPay checkout',
            'description' => 'Pay on the secure payment page',
            'rail' => 'mobile_money',
        ];

        return $providers;
    }
}
