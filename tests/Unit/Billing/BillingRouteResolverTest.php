<?php

namespace Tests\Unit\Billing;

use App\Billing\Routing\BillingFallbackChain;
use App\Billing\Routing\BillingRouteDecision;
use App\Billing\Routing\BillingRouteRequest;
use App\Billing\Routing\BillingRouteResolver;
use App\Billing\Support\BillingSurface;
use App\Billing\Support\ExecutionMode;
use App\Billing\Support\TransportMode;
use Tests\TestCase;

class BillingRouteResolverTest extends TestCase
{
    public function test_route_resolver_prefers_exact_match_then_falls_back_to_market_default(): void
    {
        $resolver = new BillingRouteResolver;
        $resolver->register(
            'wallet_funding:14:any:any',
            new BillingRouteDecision(
                providerKey: 'kopokopo',
                surface: BillingSurface::WalletFunding,
                executionMode: ExecutionMode::Direct,
                providerProfileKey: 'ke-kopo-primary',
                transportMode: TransportMode::Push,
                fallbackChain: new BillingFallbackChain([
                    ['provider_key' => 'daraja', 'execution_mode' => ExecutionMode::Direct, 'reason' => 'market-default-fallback'],
                ])
            )
        );
        $resolver->register(
            'wallet_funding:14:kes:direct',
            new BillingRouteDecision(
                providerKey: 'daraja',
                surface: BillingSurface::WalletFunding,
                executionMode: ExecutionMode::Direct,
                providerProfileKey: 'ke-daraja-primary',
                transportMode: TransportMode::Push,
                fallbackChain: new BillingFallbackChain([
                    ['provider_key' => 'kopokopo', 'execution_mode' => ExecutionMode::Direct, 'reason' => 'secondary-direct'],
                ])
            )
        );

        $exact = $resolver->resolve(new BillingRouteRequest(
            surface: BillingSurface::WalletFunding,
            marketId: 14,
            currencyCode: 'KES',
            preferredExecutionMode: ExecutionMode::Direct
        ));
        $fallback = $resolver->resolve(new BillingRouteRequest(
            surface: BillingSurface::WalletFunding,
            marketId: 14,
            currencyCode: 'USD',
            preferredExecutionMode: ExecutionMode::Proxy
        ));

        $this->assertSame('daraja', $exact?->providerKey);
        $this->assertSame(['kopokopo'], $exact?->fallbackChain->providerKeys());
        $this->assertSame('kopokopo', $fallback?->providerKey);
        $this->assertSame(['daraja'], $fallback?->fallbackChain->providerKeys());
    }
}
