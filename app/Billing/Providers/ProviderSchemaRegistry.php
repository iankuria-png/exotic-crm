<?php

namespace App\Billing\Providers;

use App\Billing\Contracts\ProviderCredentialSchema;
use App\Billing\Contracts\ProviderCredentialSchemaRegistry as ProviderCredentialSchemaRegistryContract;

class ProviderSchemaRegistry implements ProviderCredentialSchemaRegistryContract
{
    /**
     * @var array<string, ProviderCredentialSchema>
     */
    private array $schemas = [];

    /**
     * @param  iterable<int, ProviderCredentialSchema>  $schemas
     */
    public function __construct(iterable $schemas = [])
    {
        foreach ($schemas as $schema) {
            $this->register($schema);
        }
    }

    /**
     * @return array<string, ProviderCredentialSchema>
     */
    public function all(): array
    {
        return $this->schemas;
    }

    public function find(string $providerKey): ?ProviderCredentialSchema
    {
        $providerKey = strtolower(trim($providerKey));

        return $this->schemas[$providerKey] ?? null;
    }

    public function has(string $providerKey): bool
    {
        return $this->find($providerKey) !== null;
    }

    public function register(ProviderCredentialSchema $schema): void
    {
        $this->schemas[strtolower(trim($schema->providerKey()))] = $schema;
    }
}
