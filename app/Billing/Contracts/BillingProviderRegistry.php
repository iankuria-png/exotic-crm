<?php

namespace App\Billing\Contracts;

use App\Billing\Providers\ProviderDefinition;
use App\Billing\Support\BillingSurface;

interface BillingProviderRegistry
{
    /**
     * @return array<string, BillingProviderAdapter>
     */
    public function all(): array;

    /**
     * @return array<string, ProviderDefinition>
     */
    public function definitions(): array;

    /**
     * @return list<string>
     */
    public function keys(): array;

    /**
     * @return list<string>
     */
    public function legacyWalletProviderKeys(): array;

    /**
     * @return list<string>
     */
    public function keysForSurface(BillingSurface $surface): array;

    public function find(string $providerKey): ?BillingProviderAdapter;

    public function has(string $providerKey): bool;
}
