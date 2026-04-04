<?php

namespace App\Providers;

use App\Billing\Contracts\BillingDiagnosticsAssembler as BillingDiagnosticsAssemblerContract;
use App\Billing\Contracts\BillingProviderRegistry as BillingProviderRegistryContract;
use App\Billing\Contracts\BillingRouteResolver as BillingRouteResolverContract;
use App\Billing\Contracts\ProviderCredentialSchemaRegistry as ProviderCredentialSchemaRegistryContract;
use App\Billing\Diagnostics\BillingDiagnosticsAssembler;
use App\Billing\Providers\ProviderCatalog;
use App\Billing\Providers\ProviderRegistry;
use App\Billing\Providers\ProviderSchemaCatalog;
use App\Billing\Providers\ProviderSchemaRegistry;
use App\Billing\Routing\BillingRouteResolver;
use App\Services\Routing\ProviderRoutingDispatcher;
use App\Services\Routing\HostedCheckoutRoutingExecutor;
use App\Services\Routing\MpesaStkRoutingExecutor;
use App\Services\Routing\SubscriptionRoutingExecutor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $normalizedBilling = $this->normalizedBillingConfig();

        $this->app->singleton(BillingProviderRegistryContract::class, static fn (): ProviderRegistry => new ProviderRegistry(
            ProviderCatalog::adapters()
        ));
        $this->app->singleton(ProviderCredentialSchemaRegistryContract::class, static fn (): ProviderSchemaRegistry => new ProviderSchemaRegistry(
            ProviderSchemaCatalog::schemas()
        ));
        $this->app->singleton(BillingDiagnosticsAssemblerContract::class, BillingDiagnosticsAssembler::class);
        $this->app->singleton(BillingRouteResolverContract::class, BillingRouteResolver::class);

        // Register provider routing executors
        $this->app->singleton(ProviderRoutingDispatcher::class, function ($app) {
            $dispatcher = new ProviderRoutingDispatcher();
            
            // Register hosted checkout executor
            $dispatcher->register(
                $app->make(HostedCheckoutRoutingExecutor::class),
                'paystack',
                'pesapal'
            );
            
            // Register M-Pesa STK executor
            $dispatcher->register(
                $app->make(MpesaStkRoutingExecutor::class),
                'mpesa_stk'
            );
            
            return $dispatcher;
        });

        // Subscription routing executor uses dispatcher internally
        $this->app->singleton(SubscriptionRoutingExecutor::class, function ($app) {
            return new SubscriptionRoutingExecutor($app->make(ProviderRoutingDispatcher::class));
        });

        config()->set('billing', array_replace_recursive(
            (array) config('billing', []),
            $normalizedBilling
        ));

        config()->set('services.billing', array_replace_recursive(
            (array) config('services.billing', []),
            $normalizedBilling
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    private function normalizedBillingConfig(): array
    {
        $features = [
            'registry' => $this->billingFlag('billing.registry.enabled'),
            'routing' => $this->billingFlag('billing.routing.enabled'),
            'provider_transactions' => $this->billingFlag('billing.provider_transactions.enabled'),
            'dual_write' => $this->billingFlag('billing.dual_write.enabled'),
            'shadow_read' => $this->billingFlag('billing.shadow_read.enabled'),
            'billing_system_live_read' => $this->billingFlag('billing.billing_system_live_read.enabled'),
            'diagnostics_v2' => $this->billingFlag('billing.diagnostics.v2.enabled'),
            'wordpress_versioned_payloads' => $this->billingFlag('billing.wordpress.versioned_payloads.enabled'),
            'wallet_auto_renew' => $this->billingFlag('billing.wallet_auto_renew.enabled'),
            'workspace' => $this->billingFlag('billing.workspace.enabled'),
        ];

        $providerFamilies = [];

        foreach ($this->providerFamilyDefaults() as $providerFamily => $default) {
            $providerFamilies[$providerFamily] = [
                'enabled' => $this->billingFlag("billing.provider_family.{$providerFamily}.enabled", $default),
            ];
        }

        return [
            'enabled' => $this->billingFlag('billing.enabled'),
            'features' => $features,
            'registry' => [
                'enabled' => $features['registry'],
            ],
            'routing' => [
                'enabled' => $features['routing'],
            ],
            'provider_transactions' => [
                'enabled' => $features['provider_transactions'],
            ],
            'dual_write' => [
                'enabled' => $features['dual_write'],
            ],
            'shadow_read' => [
                'enabled' => $features['shadow_read'],
            ],
            'billing_system_live_read' => [
                'enabled' => $features['billing_system_live_read'],
            ],
            'diagnostics' => [
                'v2' => [
                    'enabled' => $features['diagnostics_v2'],
                ],
            ],
            'wordpress' => [
                'versioned_payloads' => [
                    'enabled' => $features['wordpress_versioned_payloads'],
                ],
            ],
            'wallet_auto_renew' => [
                'enabled' => $features['wallet_auto_renew'],
            ],
            'workspace' => [
                'enabled' => $features['workspace'],
            ],
            'provider_family' => $providerFamilies,
            'market_surface_cutover' => (array) config('billing.market_surface_cutover', []),
        ];
    }

    private function providerFamilyDefaults(): array
    {
        return [
            'daraja' => false,
            'kopokopo' => false,
            'pawapay' => false,
            'elemitech' => false,
            'dusupay' => false,
            'nowpayments' => false,
            'pesapal' => false,
            'paystack' => false,
            'paypal' => false,
        ];
    }

    private function billingFlag(string $path, bool $default = false): bool
    {
        return (bool) config($path, $default);
    }
}
