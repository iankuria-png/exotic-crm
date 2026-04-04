<?php

namespace App\Billing\Routing;

use App\Billing\Support\BillingSurface;
use App\Billing\Support\ExecutionMode;
use App\Billing\Support\TransportMode;

final class BillingRouteDecision
{
    /**
     * @param  array<string, mixed>  $context
     */
    public readonly BillingFallbackChain $fallbackChain;

    public function __construct(
        public readonly string $providerKey,
        public readonly BillingSurface $surface,
        public readonly ExecutionMode $executionMode,
        public readonly ?string $providerProfileKey = null,
        public readonly ?string $currencyCode = null,
        public readonly ?string $environment = null,
        public readonly ?TransportMode $transportMode = null,
        ?BillingFallbackChain $fallbackChain = null,
        public readonly array $context = []
    ) {
        $this->fallbackChain = $fallbackChain ?? BillingFallbackChain::empty();
    }
}
