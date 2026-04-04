<?php

namespace App\Billing\Contracts;

interface ProviderCredentialSchema
{
    public function providerKey(): string;

    public function label(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function fields(): array;

    /**
     * @return list<string>
     */
    public function supportedEnvironments(): array;
}
