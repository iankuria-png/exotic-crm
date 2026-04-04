<?php

namespace App\Billing\Providers;

use App\Billing\Contracts\BillingProviderAdapter;

final class StaticProviderAdapter implements BillingProviderAdapter
{
    public function __construct(
        private readonly ProviderDefinition $definition
    ) {
    }

    public function definition(): ProviderDefinition
    {
        return $this->definition;
    }
}
