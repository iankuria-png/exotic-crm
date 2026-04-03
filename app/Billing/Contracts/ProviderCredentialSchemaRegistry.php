<?php

namespace App\Billing\Contracts;

interface ProviderCredentialSchemaRegistry
{
    /**
     * @return array<string, ProviderCredentialSchema>
     */
    public function all(): array;

    public function find(string $providerKey): ?ProviderCredentialSchema;

    public function has(string $providerKey): bool;
}
