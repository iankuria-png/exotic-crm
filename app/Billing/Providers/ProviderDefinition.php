<?php

namespace App\Billing\Providers;

use App\Billing\Support\BillingRail;
use App\Billing\Support\BillingSurface;
use App\Billing\Support\ExecutionMode;
use App\Billing\Support\ProviderCapabilitySet;
use App\Billing\Support\ProviderFamily;
use App\Billing\Support\TransportMode;

final class ProviderDefinition
{
    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $currencyCodes
     * @param  list<string>  $countryCodes
     * @param  array<string, mixed>  $restrictions
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ProviderFamily $family,
        public readonly ProviderCapabilitySet $capabilities,
        public readonly array $aliases = [],
        public readonly array $currencyCodes = [],
        public readonly array $countryCodes = [],
        public readonly array $restrictions = [],
        public readonly array $meta = []
    ) {
    }

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

    public function supportsCurrency(?string $currencyCode): bool
    {
        if ($currencyCode === null || trim($currencyCode) === '' || $this->currencyCodes === []) {
            return true;
        }

        return in_array(strtoupper(trim($currencyCode)), array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $this->currencyCodes
        ), true);
    }

    public function supportsCountry(?string $countryCode): bool
    {
        if ($countryCode === null || trim($countryCode) === '' || $this->countryCodes === []) {
            return true;
        }

        return in_array(strtoupper(trim($countryCode)), array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $this->countryCodes
        ), true);
    }

    public function supportsSurface(BillingSurface $surface): bool
    {
        return $this->capabilities->supportsSurface($surface);
    }

    public function supportsExecutionMode(ExecutionMode $executionMode): bool
    {
        return $this->capabilities->supportsExecutionMode($executionMode);
    }

    public function supportsRail(BillingRail $rail): bool
    {
        return $this->capabilities->supportsRail($rail);
    }

    public function supportsTransportMode(TransportMode $transportMode): bool
    {
        return $this->capabilities->supportsTransportMode($transportMode);
    }

    public function restriction(string $key, mixed $default = null): mixed
    {
        return $this->restrictions[$key] ?? $default;
    }

    public function meta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'family' => $this->family->value,
            'aliases' => $this->aliases,
            'currency_codes' => $this->currencyCodes,
            'country_codes' => $this->countryCodes,
            'restrictions' => $this->restrictions,
            'capabilities' => [
                'flags' => array_map(static fn ($capability) => $capability->value, $this->capabilities->capabilities()),
                'surfaces' => array_map(static fn ($surface) => $surface->value, $this->capabilities->surfaces()),
                'rails' => array_map(static fn ($rail) => $rail->value, $this->capabilities->rails()),
                'transport_modes' => array_map(static fn ($mode) => $mode->value, $this->capabilities->transportModes()),
                'operation_types' => array_map(static fn ($operationType) => $operationType->value, $this->capabilities->operationTypes()),
                'settlement_semantics' => array_map(static fn ($semantic) => $semantic->value, $this->capabilities->settlementSemantics()),
                'execution_modes' => array_map(static fn ($executionMode) => $executionMode->value, $this->capabilities->executionModes()),
            ],
            'meta' => $this->meta,
        ];
    }
}
