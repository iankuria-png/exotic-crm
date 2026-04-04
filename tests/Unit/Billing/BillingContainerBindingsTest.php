<?php

namespace Tests\Unit\Billing;

use App\Billing\Contracts\BillingDiagnosticsAssembler as BillingDiagnosticsAssemblerContract;
use App\Billing\Contracts\BillingProviderRegistry as BillingProviderRegistryContract;
use App\Billing\Contracts\BillingRouteResolver as BillingRouteResolverContract;
use App\Billing\Contracts\ProviderCredentialSchemaRegistry as ProviderCredentialSchemaRegistryContract;
use App\Billing\Diagnostics\BillingDiagnosticsAssembler;
use App\Billing\Providers\ProviderCatalog;
use App\Billing\Providers\ProviderRegistry;
use App\Billing\Providers\ProviderSchemaRegistry;
use App\Billing\Routing\BillingRouteResolver;
use Tests\TestCase;

class BillingContainerBindingsTest extends TestCase
{
    public function test_billing_namespace_contracts_resolve_to_scaffold_implementations(): void
    {
        $providerRegistry = $this->app->make(BillingProviderRegistryContract::class);

        $this->assertInstanceOf(ProviderRegistry::class, $providerRegistry);
        $this->assertInstanceOf(ProviderSchemaRegistry::class, $this->app->make(ProviderCredentialSchemaRegistryContract::class));
        $this->assertInstanceOf(BillingDiagnosticsAssembler::class, $this->app->make(BillingDiagnosticsAssemblerContract::class));
        $this->assertInstanceOf(BillingRouteResolver::class, $this->app->make(BillingRouteResolverContract::class));
        $this->assertCount(count(ProviderCatalog::definitions()), $providerRegistry->all());
        $this->assertTrue($providerRegistry->has('pesapal'));
    }
}
