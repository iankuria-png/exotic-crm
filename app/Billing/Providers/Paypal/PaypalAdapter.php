<?php

namespace App\Billing\Providers\Paypal;

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

class PaypalAdapter extends AbstractProviderAdapter
{
    protected function makeDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'paypal',
            label: 'PayPal',
            family: ProviderFamily::Paypal,
            capabilities: new ProviderCapabilitySet(
                capabilities: [
                    ProviderCapability::Webhooks,
                    ProviderCapability::StatusQueries,
                    ProviderCapability::SandboxAvailable,
                ],
                surfaces: [
                    BillingSurface::SubscriptionLink,
                    BillingSurface::SelfCheckout,
                ],
                rails: [BillingRail::Card, BillingRail::BankTransfer],
                transportModes: [TransportMode::Redirect],
                operationTypes: [
                    ProviderOperationType::Initiate,
                    ProviderOperationType::StatusQuery,
                    ProviderOperationType::WebhookVerify,
                ],
                settlementSemantics: [SettlementSemantics::Delayed],
                executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
            ),
            restrictions: [
                'near_term_deferred' => true,
            ],
            meta: [
                'legacy_wallet_selectable' => false,
                'deferred' => true,
                'status' => 'deferred',
                'docs_url' => 'https://developer.paypal.com/docs/subscriptions/',
            ]
        );
    }
}
