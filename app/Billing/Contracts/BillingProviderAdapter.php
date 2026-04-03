<?php

namespace App\Billing\Contracts;

use App\Billing\Providers\ProviderDefinition;

interface BillingProviderAdapter
{
    public function definition(): ProviderDefinition;
}
