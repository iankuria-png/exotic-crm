<?php

namespace App\Billing\Providers;

use App\Billing\Contracts\BillingProviderAdapter;

abstract class AbstractProviderAdapter implements BillingProviderAdapter
{
    private ?ProviderDefinition $definition = null;

    final public function definition(): ProviderDefinition
    {
        return $this->definition ??= $this->makeDefinition();
    }

    abstract protected function makeDefinition(): ProviderDefinition;
}
