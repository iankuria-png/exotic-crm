<?php

namespace App\Billing\Contracts;

use App\Billing\Routing\BillingRouteDecision;
use App\Billing\Routing\BillingRouteRequest;

interface BillingRouteResolver
{
    public function resolve(BillingRouteRequest $request): ?BillingRouteDecision;
}
