<?php

namespace App\Billing\Providers\NOWPayments;

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

class NowPaymentsAdapter extends AbstractProviderAdapter
{
    protected function makeDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'nowpayments',
            label: 'NOWPayments',
            family: ProviderFamily::Nowpayments,
            capabilities: new ProviderCapabilitySet(
                capabilities: [
                    ProviderCapability::Webhooks,
                    ProviderCapability::StatusQueries,
                    ProviderCapability::PartialAmountTolerance,
                ],
                surfaces: [
                    BillingSurface::SubscriptionLink,
                    BillingSurface::SelfCheckout,
                ],
                rails: [BillingRail::Crypto],
                transportModes: [TransportMode::InvoiceAddress, TransportMode::Redirect],
                operationTypes: [
                    ProviderOperationType::Initiate,
                    ProviderOperationType::StatusQuery,
                    ProviderOperationType::WebhookVerify,
                ],
                settlementSemantics: [SettlementSemantics::InvoiceExpiryBased],
                executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
            ),
            restrictions: [
                'wallet_funding_supported' => false,
                'subscription_push_supported' => false,
            ],
            meta: [
                'legacy_wallet_selectable' => false,
                'status' => 'active',
                'docs_url' => 'https://nowpayments.io/api',
            ]
        );
    }
}
