<?php

namespace App\Billing\Routing;

use App\Billing\Support\BillingSurface;
use App\Billing\Support\ExecutionMode;

final class BillingRouteDecision
{
    /**
     * @param  list<string>  $fallbackProviderKeys
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly BillingSurface $surface,
        public readonly ExecutionMode $executionMode,
        public readonly ?string $providerProfileKey = null,
        public readonly array $fallbackProviderKeys = [],
        public readonly array $context = []
    ) {}
}
