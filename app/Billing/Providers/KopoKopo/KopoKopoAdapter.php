<?php

namespace App\Billing\Providers\KopoKopo;

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

class KopoKopoAdapter extends AbstractProviderAdapter
{
    protected function makeDefinition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'kopokopo',
            label: 'KopoKopo',
            family: ProviderFamily::Kopokopo,
            capabilities: new ProviderCapabilitySet(
                capabilities: [
                    ProviderCapability::Webhooks,
                    ProviderCapability::StatusQueries,
                    ProviderCapability::Polling,
                ],
                surfaces: [
                    BillingSurface::WalletFunding,
                    BillingSurface::SubscriptionPush,
                    BillingSurface::WalletAutoRenew,
                ],
                rails: [BillingRail::MobileMoney],
                transportModes: [TransportMode::Push, TransportMode::ServerToServerCollection],
                operationTypes: [
                    ProviderOperationType::Initiate,
                    ProviderOperationType::StatusQuery,
                    ProviderOperationType::WebhookVerify,
                ],
                settlementSemantics: [SettlementSemantics::ConfirmationBased],
                executionModes: [ExecutionMode::Direct, ExecutionMode::Proxy]
            ),
            aliases: ['kopo', 'k2'],
            currencyCodes: ['KES'],
            countryCodes: ['KE'],
            restrictions: [
                'kenya_only' => true,
            ],
            meta: [
                'legacy_wallet_selectable' => false,
                'status' => 'active',
                'docs_url' => 'https://api-docs.kopokopo.com/',
            ]
        );
    }
}
