<?php

namespace Tests\Unit\Billing;

use App\Billing\Contracts\BillingProviderRegistry as BillingProviderRegistryContract;
use App\Billing\Providers\ProviderCatalog;
use App\Billing\Support\BillingRail;
use App\Billing\Support\BillingSurface;
use App\Billing\Support\ExecutionMode;
use Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    public function test_provider_registry_resolves_catalog_keys_and_aliases_case_insensitively(): void
    {
        $registry = $this->app->make(BillingProviderRegistryContract::class);

        $this->assertTrue($registry->has('KopoKopo'));
        $this->assertSame('kopokopo', $registry->find('kopo')?->definition()->key);
        $this->assertTrue($registry->find('K2')?->definition()->capabilities->supportsRail(BillingRail::MobileMoney));
        $this->assertTrue($registry->find('kopokopo')?->definition()->capabilities->supportsSurface(BillingSurface::WalletFunding));
        $this->assertTrue($registry->find('daraja')?->definition()->capabilities->supportsExecutionMode(ExecutionMode::Proxy));
    }

    public function test_provider_catalog_declares_legacy_wallet_subset_and_constraints_beyond_surface_flags(): void
    {
        $registry = $this->app->make(BillingProviderRegistryContract::class);

        $this->assertSame(['pesapal', 'paystack', 'mpesa_stk'], $registry->legacyWalletProviderKeys());
        $this->assertTrue($registry->find('nowpayments')?->definition()->supportsCurrency('BTC'));
        $this->assertFalse($registry->find('nowpayments')?->definition()->capabilities->supportsSurface(BillingSurface::WalletFunding));
        $this->assertTrue((bool) $registry->find('mpesa_stk')?->definition()->restriction('transitional_transport_alias'));
        $this->assertCount(count(ProviderCatalog::definitions()), $registry->definitions());
    }
}
