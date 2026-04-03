<?php

namespace App\Billing\Providers;

use App\Billing\Support\ProviderCapabilitySet;

final class ProviderDefinition
{
    /**
     * @param  list<string>  $aliases
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ProviderCapabilitySet $capabilities,
        public readonly array $aliases = [],
        public readonly array $meta = []
    ) {}

    public function matches(string $providerKey): bool
    {
        $normalized = strtolower(trim($providerKey));

        if ($normalized === strtolower(trim($this->key))) {
            return true;
        }

        foreach ($this->aliases as $alias) {
            if ($normalized === strtolower(trim($alias))) {
                return true;
            }
        }

        return false;
    }
}
