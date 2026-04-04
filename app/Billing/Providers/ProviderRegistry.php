<?php

namespace App\Billing\Providers;

use App\Billing\Contracts\BillingProviderAdapter;
use App\Billing\Contracts\BillingProviderRegistry as BillingProviderRegistryContract;
use App\Billing\Support\BillingSurface;

class ProviderRegistry implements BillingProviderRegistryContract
{
    /**
     * @var array<string, BillingProviderAdapter>
     */
    private array $providers = [];

    /**
     * @param  iterable<int, BillingProviderAdapter>  $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    /**
     * @return array<string, BillingProviderAdapter>
     */
    public function all(): array
    {
        return $this->providers;
    }

    public function definitions(): array
    {
        return array_map(
            static fn (BillingProviderAdapter $provider): ProviderDefinition => $provider->definition(),
            $this->providers
        );
    }

    public function keys(): array
    {
        return array_values(array_map(
            static fn (ProviderDefinition $definition): string => $definition->key,
            $this->definitions()
        ));
    }

    public function legacyWalletProviderKeys(): array
    {
        return array_values(array_map(
            static fn (ProviderDefinition $definition): string => $definition->key,
            array_filter(
                $this->definitions(),
                static fn (ProviderDefinition $definition): bool => (bool) $definition->meta('legacy_wallet_selectable', false)
            )
        ));
    }

    public function find(string $providerKey): ?BillingProviderAdapter
    {
        $lookup = strtolower(trim($providerKey));

        if (isset($this->providers[$lookup])) {
            return $this->providers[$lookup];
        }

        foreach ($this->providers as $provider) {
            if ($provider->definition()->matches($lookup)) {
                return $provider;
            }
        }

        return null;
    }

    public function has(string $providerKey): bool
    {
        return $this->find($providerKey) !== null;
    }

    public function register(BillingProviderAdapter $provider): void
    {
        $this->providers[strtolower(trim($provider->definition()->key))] = $provider;
    }

    /**
     * @return list<string>
     */
    public function keysForSurface(BillingSurface $surface): array
    {
        return array_values(array_map(
            static fn (ProviderDefinition $definition): string => $definition->key,
            array_filter(
                $this->definitions(),
                static fn (ProviderDefinition $definition): bool => $definition->capabilities->supportsSurface($surface)
            )
        ));
    }
}
