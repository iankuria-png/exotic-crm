<?php

namespace App\Billing\Providers\ElemiTech;

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

class ElemiTechAdapter extends AbstractProviderAdapter
{
    protected function makeDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'elemitech',
            label: 'ElemiTech',
            family: ProviderFamily::Elemitech,
            capabilities: new ProviderCapabilitySet(
                capabilities: [
                    ProviderCapability::Webhooks,
                    ProviderCapability::StatusQueries,
                    ProviderCapability::Polling,
                ],
                surfaces: [
                    BillingSurface::WalletFunding,
                    BillingSurface::SubscriptionLink,
                    BillingSurface::SelfCheckout,
                ],
                rails: [BillingRail::MobileMoney, BillingRail::BankTransfer],
                transportModes: [TransportMode::ServerToServerCollection, TransportMode::Redirect],
                operationTypes: [
                    ProviderOperationType::Initiate,
                    ProviderOperationType::StatusQuery,
                    ProviderOperationType::WebhookVerify,
                ],
                settlementSemantics: [SettlementSemantics::Delayed],
                executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
            ),
            countryCodes: ['GH', 'KE', 'UG', 'TZ', 'ZM'],
            meta: [
                'legacy_wallet_selectable' => false,
                'status' => 'active',
                'docs_url' => 'https://docs.elemitech.com/funds-collection/mobile-money-collection',
            ]
        );
    }
}
