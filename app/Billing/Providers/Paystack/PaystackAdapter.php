<?php

namespace App\Billing\Providers\Paystack;

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

class PaystackAdapter extends AbstractProviderAdapter
{
    protected function makeDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'paystack',
            label: 'Paystack',
            family: ProviderFamily::Paystack,
            capabilities: new ProviderCapabilitySet(
                capabilities: [
                    ProviderCapability::Webhooks,
                    ProviderCapability::StatusQueries,
                    ProviderCapability::SandboxAvailable,
                ],
                surfaces: [
                    BillingSurface::WalletFunding,
                    BillingSurface::SubscriptionLink,
                    BillingSurface::SelfCheckout,
                    BillingSurface::ProxyHostedCheckout,
                ],
                rails: [BillingRail::Card, BillingRail::MobileMoney, BillingRail::BankTransfer],
                transportModes: [TransportMode::Redirect],
                operationTypes: [
                    ProviderOperationType::Initiate,
                    ProviderOperationType::StatusQuery,
                    ProviderOperationType::WebhookVerify,
                ],
                settlementSemantics: [SettlementSemantics::Delayed],
                executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
            ),
            currencyCodes: ['GHS', 'KES', 'NGN', 'USD', 'ZAR'],
            countryCodes: ['GH', 'KE', 'NG', 'ZA'],
            restrictions: [
                'hosted_checkout_only' => true,
                'near_term_deferred' => true,
            ],
            meta: [
                'legacy_wallet_selectable' => true,
                'deferred' => true,
                'status' => 'deferred',
                'docs_url' => 'https://paystack.com/docs/',
            ]
        );
    }
}
