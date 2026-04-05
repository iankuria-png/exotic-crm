<?php

namespace App\Billing\Providers\Daraja;

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

class MpesaStkCompatibilityAdapter extends AbstractProviderAdapter
{
    protected function makeDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'mpesa_stk',
            label: 'M-Pesa STK',
            family: ProviderFamily::Daraja,
            capabilities: new ProviderCapabilitySet(
                capabilities: [
                    ProviderCapability::Polling,
                    ProviderCapability::StatusQueries,
                ],
                surfaces: [
                    BillingSurface::WalletFunding,
                    BillingSurface::SubscriptionPush,
                    BillingSurface::WalletAutoRenew,
                ],
                rails: [BillingRail::MobileMoney],
                transportModes: [TransportMode::Push, TransportMode::DjangoProxy],
                operationTypes: [
                    ProviderOperationType::Initiate,
                    ProviderOperationType::StatusQuery,
                ],
                settlementSemantics: [SettlementSemantics::ConfirmationBased],
                executionModes: [ExecutionMode::Direct, ExecutionMode::Transitional]
            ),
            aliases: ['m-pesa-stk'],
            currencyCodes: ['KES'],
            countryCodes: ['KE'],
            restrictions: [
                'transitional_transport_alias' => true,
                'proxy_checkout_supported' => false,
            ],
            meta: [
                'legacy_wallet_selectable' => true,
                'compatibility_alias_for' => 'daraja',
                'status' => 'compatibility',
                'docs_url' => 'https://developer.safaricom.co.ke/',
            ]
        );
    }
}
