<?php

namespace App\Billing\Routing;

use App\Billing\Contracts\BillingRouteResolver as BillingRouteResolverContract;

class BillingRouteResolver implements BillingRouteResolverContract
{
    /**
     * @var array<string, BillingRouteDecision>
     */
    private array $decisions = [];

    /**
     * @param  array<string, BillingRouteDecision>  $decisions
     */
    public function __construct(array $decisions = [])
    {
        $this->decisions = $decisions;
    }

    public function resolve(BillingRouteRequest $request): ?BillingRouteDecision
    {
        return $this->decisions[$request->lookupKey()] ?? null;
    }

    public function register(string $lookupKey, BillingRouteDecision $decision): void
    {
        $this->decisions[$lookupKey] = $decision;
    }
}
