<?php

namespace App\Billing\Providers\PawaPay;

use App\Billing\Providers\AbstractProviderAdapter;
use App\Billing\Providers\ProviderDefinition;
use App\Billing\Support\BillingRail;
use App\Billing\Support\BillingSurface;
use App\Billing\Support\ExecutionMode;
use App\Billing\Support\ProviderCapability;
use App\Billing\Support\ProviderCapabilitySet;
use App\Billing\Support\ProviderFamily;
use App\Billing\Support\ProviderOperationType;
use App\Billing\Support\SettlementSemantics;
use App\Billing\Support\TransportMode;

class PawaPayAdapter extends AbstractProviderAdapter
{
    protected function makeDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'pawapay',
            label: 'pawaPay',
            family: ProviderFamily::Pawapay,
            capabilities: new ProviderCapabilitySet(
                capabilities: [
                    ProviderCapability::Webhooks,
                    ProviderCapability::StatusQueries,
                    ProviderCapability::Polling,
                    ProviderCapability::SandboxAvailable,
                ],
                surfaces: [
                    BillingSurface::WalletFunding,
                    BillingSurface::SubscriptionLink,
                    BillingSurface::SelfCheckout,
                    BillingSurface::ProxyHostedCheckout,
                ],
                rails: [BillingRail::MobileMoney],
                transportModes: [TransportMode::ServerToServerCollection, TransportMode::Redirect],
                operationTypes: [
                    ProviderOperationType::Initiate,
                    ProviderOperationType::StatusQuery,
                    ProviderOperationType::WebhookVerify,
                ],
                settlementSemantics: [SettlementSemantics::Delayed],
                executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
            ),
            countryCodes: ['CD', 'GH', 'KE', 'MW', 'MZ', 'UG', 'TZ', 'ZM'],
            restrictions: [
                'adult_content_suitability_requires_review' => true,
            ],
            meta: [
                'legacy_wallet_selectable' => false,
                'status' => 'active',
                'docs_url' => 'https://docs.pawapay.io/',
            ]
        );
    }
}
