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
        foreach ($request->candidateLookupKeys() as $lookupKey) {
            if (isset($this->decisions[$lookupKey])) {
                return $this->decisions[$lookupKey];
            }
        }

        return null;
    }

    public function register(string $lookupKey, BillingRouteDecision $decision): void
    {
        $this->decisions[$lookupKey] = $decision;
    }
}
