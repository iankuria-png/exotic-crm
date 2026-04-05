<?php

namespace App\Billing\Providers\Pesapal;

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

class PesapalAdapter extends AbstractProviderAdapter
{
    protected function makeDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'pesapal',
            label: 'Pesapal',
            family: ProviderFamily::Pesapal,
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
                rails: [BillingRail::Card, BillingRail::MobileMoney],
                transportModes: [TransportMode::Redirect],
                operationTypes: [
                    ProviderOperationType::Initiate,
                    ProviderOperationType::StatusQuery,
                    ProviderOperationType::WebhookVerify,
                ],
                settlementSemantics: [SettlementSemantics::Delayed],
                executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
            ),
            currencyCodes: ['KES', 'UGX', 'TZS', 'USD'],
            countryCodes: ['KE', 'UG', 'TZ'],
            restrictions: [
                'hosted_checkout_only' => true,
                'supports_wallet_topup' => true,
            ],
            meta: [
                'legacy_wallet_selectable' => true,
                'status' => 'compatibility',
                'docs_url' => 'https://developer.pesapal.com/how-to-integrate/ecommerce/api-30-json',
            ]
        );
    }
}
