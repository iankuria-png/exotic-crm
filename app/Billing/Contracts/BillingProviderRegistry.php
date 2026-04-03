<?php

namespace App\Billing\Contracts;

interface BillingProviderRegistry
{
    /**
     * @return array<string, BillingProviderAdapter>
     */
    public function all(): array;

    public function find(string $providerKey): ?BillingProviderAdapter;

    public function has(string $providerKey): bool;
}
