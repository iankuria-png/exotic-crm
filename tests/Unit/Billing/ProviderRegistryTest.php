<?php

namespace Tests\Unit\Billing;

use App\Billing\Contracts\BillingProviderAdapter;
use App\Billing\Providers\ProviderDefinition;
use App\Billing\Providers\ProviderRegistry;
use App\Billing\Support\BillingRail;
use App\Billing\Support\BillingSurface;
use App\Billing\Support\ExecutionMode;
use App\Billing\Support\ProviderCapability;
use App\Billing\Support\ProviderCapabilitySet;
use App\Billing\Support\ProviderOperationType;
use App\Billing\Support\SettlementSemantics;
use App\Billing\Support\TransportMode;
use Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    public function test_provider_registry_resolves_primary_keys_and_aliases_case_insensitively(): void
    {
        $provider = new class implements BillingProviderAdapter
        {
            public function definition(): ProviderDefinition
            {
                return new ProviderDefinition(
                    key: 'kopokopo',
                    label: 'KopoKopo',
                    capabilities: new ProviderCapabilitySet(
                        capabilities: [ProviderCapability::Webhooks, ProviderCapability::StatusQueries],
                        surfaces: [BillingSurface::WalletFunding, BillingSurface::SelfCheckout],
                        rails: [BillingRail::MobileMoney],
                        transportModes: [TransportMode::ServerToServerCollection],
                        operationTypes: [ProviderOperationType::Initiate, ProviderOperationType::StatusQuery],
                        settlementSemantics: [SettlementSemantics::Delayed],
                        executionModes: [ExecutionMode::Direct]
                    ),
                    aliases: ['kopo', 'k2']
                );
            }
        };

        $registry = new ProviderRegistry([$provider]);

        $this->assertTrue($registry->has('KopoKopo'));
        $this->assertSame($provider, $registry->find('kopo'));
        $this->assertTrue($registry->find('K2')->definition()->capabilities->supportsRail(BillingRail::MobileMoney));
        $this->assertTrue($registry->find('kopokopo')->definition()->capabilities->supportsTransportMode(TransportMode::ServerToServerCollection));
        $this->assertTrue($registry->find('kopokopo')->definition()->capabilities->supportsSettlementSemantics(SettlementSemantics::Delayed));
    }
}
