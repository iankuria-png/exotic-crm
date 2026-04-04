<?php

namespace App\Billing\Repositories;

use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingRoutingDecision;
use App\Models\BillingRoutingRule;
use App\Models\BillingSubscriptionRule;
use App\Models\BillingWalletRule;
use Illuminate\Support\Collection;

class BillingConfigurationRepository
{
    /**
     * @return Collection<int, BillingProviderProfile>
     */
    public function providerProfilesForMarket(int $marketId, ?string $environment = null): Collection
    {
        return BillingProviderProfile::query()
            ->where(function ($query) use ($marketId) {
                $query->where('market_id', $marketId)
                    ->orWhereNull('market_id');
            })
            ->when($environment, fn ($query) => $query->where('environment', strtolower(trim($environment))))
            ->orderByDesc('market_id')
            ->orderBy('provider_type_key')
            ->orderBy('profile_name')
            ->get();
    }

    /**
     * @return Collection<int, BillingMarketProviderBinding>
     */
    public function activeBindingsForMarket(int $marketId, ?string $billingSurface = null): Collection
    {
        return BillingMarketProviderBinding::query()
            ->where('market_id', $marketId)
            ->where('enabled', true)
            ->when($billingSurface, fn ($query) => $query->where('billing_surface', $billingSurface))
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    public function routingRuleForMarket(int $marketId, string $billingSurface): ?BillingRoutingRule
    {
        return BillingRoutingRule::query()
            ->where('market_id', $marketId)
            ->where('billing_surface', $billingSurface)
            ->where('active', true)
            ->first();
    }

    public function walletRuleForMarket(int $marketId): ?BillingWalletRule
    {
        return BillingWalletRule::query()
            ->where('market_id', $marketId)
            ->first();
    }

    public function subscriptionRuleForMarket(int $marketId): ?BillingSubscriptionRule
    {
        return BillingSubscriptionRule::query()
            ->where('market_id', $marketId)
            ->first();
    }

    public function latestRoutingDecisionForPayment(int $paymentId): ?BillingRoutingDecision
    {
        return BillingRoutingDecision::query()
            ->where('payment_id', $paymentId)
            ->latest('id')
            ->first();
    }
}
