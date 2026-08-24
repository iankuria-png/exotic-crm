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

    public function updateAvailability(bool $enabled, array $marketIds, ?int $actorId = null): void
    {
        $this->featureSettings->set('contact_unlock.enabled', $enabled, $actorId);
        $this->featureSettings->set('contact_unlock.market_ids', array_values(array_unique(array_filter(array_map('intval', $marketIds)))), $actorId);
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
                'label' => 'M-Pesa',
                'rail' => 'mobile_money',
            ];
        }

        $providers[] = [
            'key' => 'pawapay',
            'label' => 'Mobile money',
            'rail' => 'mobile_money',
        ];

        return $providers;
    }
}
