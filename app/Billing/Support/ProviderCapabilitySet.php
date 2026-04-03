<?php

namespace App\Billing\Support;

final class ProviderCapabilitySet
{
    /**
     * @var array<string, ProviderCapability>
     */
    private array $capabilities = [];

    /**
     * @var array<string, BillingSurface>
     */
    private array $surfaces = [];

    /**
     * @var array<string, BillingRail>
     */
    private array $rails = [];

    /**
     * @var array<string, TransportMode>
     */
    private array $transportModes = [];

    /**
     * @var array<string, ProviderOperationType>
     */
    private array $operationTypes = [];

    /**
     * @var array<string, SettlementSemantics>
     */
    private array $settlementSemantics = [];

    /**
     * @var array<string, ExecutionMode>
     */
    private array $executionModes = [];

    /**
     * @param  iterable<int, ProviderCapability>  $capabilities
     * @param  iterable<int, BillingSurface>  $surfaces
     * @param  iterable<int, BillingRail>  $rails
     * @param  iterable<int, TransportMode>  $transportModes
     * @param  iterable<int, ProviderOperationType>  $operationTypes
     * @param  iterable<int, SettlementSemantics>  $settlementSemantics
     * @param  iterable<int, ExecutionMode>  $executionModes
     */
    public function __construct(
        iterable $capabilities = [],
        iterable $surfaces = [],
        iterable $rails = [],
        iterable $transportModes = [],
        iterable $operationTypes = [],
        iterable $settlementSemantics = [],
        iterable $executionModes = []
    ) {
        foreach ($capabilities as $capability) {
            $this->capabilities[$capability->value] = $capability;
        }

        foreach ($surfaces as $surface) {
            $this->surfaces[$surface->value] = $surface;
        }

        foreach ($rails as $rail) {
            $this->rails[$rail->value] = $rail;
        }

        foreach ($transportModes as $transportMode) {
            $this->transportModes[$transportMode->value] = $transportMode;
        }

        foreach ($operationTypes as $operationType) {
            $this->operationTypes[$operationType->value] = $operationType;
        }

        foreach ($settlementSemantics as $settlementSemantic) {
            $this->settlementSemantics[$settlementSemantic->value] = $settlementSemantic;
        }

        foreach ($executionModes as $executionMode) {
            $this->executionModes[$executionMode->value] = $executionMode;
        }
    }

    public static function empty(): self
    {
        return new self;
    }

    public function has(ProviderCapability $capability): bool
    {
        return isset($this->capabilities[$capability->value]);
    }

    public function supportsExecutionMode(ExecutionMode $executionMode): bool
    {
        return isset($this->executionModes[$executionMode->value]);
    }

    public function supportsSurface(BillingSurface $surface): bool
    {
        return isset($this->surfaces[$surface->value]);
    }

    public function supportsRail(BillingRail $rail): bool
    {
        return isset($this->rails[$rail->value]);
    }

    public function supportsTransportMode(TransportMode $transportMode): bool
    {
        return isset($this->transportModes[$transportMode->value]);
    }

    public function supportsOperationType(ProviderOperationType $operationType): bool
    {
        return isset($this->operationTypes[$operationType->value]);
    }

    public function supportsSettlementSemantics(SettlementSemantics $settlementSemantics): bool
    {
        return isset($this->settlementSemantics[$settlementSemantics->value]);
    }

    /**
     * @return list<ProviderCapability>
     */
    public function capabilities(): array
    {
        return array_values($this->capabilities);
    }

    /**
     * @return list<ExecutionMode>
     */
    public function executionModes(): array
    {
        return array_values($this->executionModes);
    }

    /**
     * @return list<ProviderOperationType>
     */
    public function operationTypes(): array
    {
        return array_values($this->operationTypes);
    }

    /**
     * @return list<BillingRail>
     */
    public function rails(): array
    {
        return array_values($this->rails);
    }

    /**
     * @return list<BillingSurface>
     */
    public function surfaces(): array
    {
        return array_values($this->surfaces);
    }

    /**
     * @return list<SettlementSemantics>
     */
    public function settlementSemantics(): array
    {
        return array_values($this->settlementSemantics);
    }

    /**
     * @return list<TransportMode>
     */
    public function transportModes(): array
    {
        return array_values($this->transportModes);
    }
}
