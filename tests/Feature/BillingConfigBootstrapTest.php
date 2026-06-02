<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Tests\TestCase;

class BillingConfigBootstrapTest extends TestCase
{
    public function test_billing_feature_flags_boot_disabled_by_default(): void
    {
        $expectedFeatures = [
            'registry' => false,
            'routing' => false,
            'provider_transactions' => false,
            'dual_write' => false,
            'shadow_read' => false,
            'billing_system_live_read' => false,
            'diagnostics_v2' => false,
            'wordpress_versioned_payloads' => false,
            'wallet_auto_renew' => false,
            'workspace' => false,
        ];
        $expectedProviderFamilies = [
            'daraja' => ['enabled' => false],
            'kopokopo' => ['enabled' => false],
            'pawapay' => ['enabled' => false],
            'elemitech' => ['enabled' => false],
            'dusupay' => ['enabled' => false],
            'nowpayments' => ['enabled' => false],
            'pesapal' => ['enabled' => false],
            'paystack' => ['enabled' => false],
            'paypal' => ['enabled' => false],
        ];

        config()->set('billing.enabled', false);
        config()->set('billing.registry.enabled', false);
        config()->set('billing.routing.enabled', false);
        config()->set('billing.provider_transactions.enabled', false);
        config()->set('billing.dual_write.enabled', false);
        config()->set('billing.shadow_read.enabled', false);
        config()->set('billing.billing_system_live_read.enabled', false);
        config()->set('billing.workspace.enabled', false);
        config()->set('billing.diagnostics.v2.enabled', false);
        config()->set('billing.wordpress.versioned_payloads.enabled', false);
        config()->set('billing.wallet_auto_renew.enabled', false);
        config()->set('billing.provider_family', $expectedProviderFamilies);
        config()->set('billing.market_surface_cutover', []);

        (new AppServiceProvider($this->app))->register();

        $this->assertFalse(config('billing.enabled'));
        $this->assertSame($expectedFeatures, config('billing.features'));
        $this->assertFalse(config('billing.registry.enabled'));
        $this->assertFalse(config('billing.shadow_read.enabled'));
        $this->assertFalse(config('billing.billing_system_live_read.enabled'));
        $this->assertFalse(config('billing.diagnostics.v2.enabled'));
        $this->assertFalse(config('billing.wordpress.versioned_payloads.enabled'));
        $this->assertFalse(config('billing.wallet_auto_renew.enabled'));
        $this->assertSame($expectedProviderFamilies, config('billing.provider_family'));
        $this->assertSame([], config('billing.market_surface_cutover'));
        $this->assertFalse(config('services.billing.enabled'));
        $this->assertSame($expectedFeatures, config('services.billing.features'));
        $this->assertSame($expectedProviderFamilies, config('services.billing.provider_family'));
        $this->assertSame([], config('services.billing.market_surface_cutover'));
    }

    public function test_billing_aliases_sync_into_services_namespace_when_flags_change(): void
    {
        config()->set('billing.enabled', true);
        config()->set('billing.registry.enabled', true);
        config()->set('billing.shadow_read.enabled', true);
        config()->set('billing.diagnostics.v2.enabled', true);
        config()->set('billing.provider_family.daraja.enabled', true);
        config()->set('billing.market_surface_cutover', [
            'kenya' => [
                'subscription_link' => true,
            ],
        ]);

        (new AppServiceProvider($this->app))->register();

        $this->assertTrue(config('billing.features.registry'));
        $this->assertTrue(config('billing.features.shadow_read'));
        $this->assertTrue(config('billing.features.diagnostics_v2'));
        $this->assertTrue(config('services.billing.enabled'));
        $this->assertTrue(config('services.billing.features.registry'));
        $this->assertTrue(config('services.billing.features.shadow_read'));
        $this->assertTrue(config('services.billing.diagnostics.v2.enabled'));
        $this->assertTrue(config('services.billing.provider_family.daraja.enabled'));
        $this->assertSame([
            'kenya' => [
                'subscription_link' => true,
            ],
        ], config('services.billing.market_surface_cutover'));
    }
}
