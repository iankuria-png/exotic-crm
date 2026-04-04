<?php

namespace App\Billing\Providers\Schemas;

use App\Billing\Contracts\ProviderCredentialSchema;

final class StaticProviderCredentialSchema implements ProviderCredentialSchema
{
    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  list<string>  $supportedEnvironments
     */
    public function __construct(
        private readonly string $providerKey,
        private readonly string $label,
        private readonly array $fields,
        private readonly array $supportedEnvironments = ['sandbox', 'production']
    ) {
    }

    public function providerKey(): string
    {
        return $this->providerKey;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function supportedEnvironments(): array
    {
        return $this->supportedEnvironments;
    }
}
