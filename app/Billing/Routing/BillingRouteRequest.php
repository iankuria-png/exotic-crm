<?php

namespace App\Billing\Routing;

use App\Billing\Support\BillingSurface;
use App\Billing\Support\ExecutionMode;

final class BillingRouteRequest
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly BillingSurface $surface,
        public readonly ?int $marketId = null,
        public readonly ?int $platformId = null,
        public readonly ?int $paymentId = null,
        public readonly ?int $clientId = null,
        public readonly ?string $currencyCode = null,
        public readonly ?ExecutionMode $preferredExecutionMode = null,
        public readonly ?string $decisionKey = null,
        public readonly array $context = []
    ) {}

    public function lookupKey(): string
    {
        if ($this->decisionKey !== null && trim($this->decisionKey) !== '') {
            return trim($this->decisionKey);
        }

        return $this->composeLookupKey(
            $this->marketId,
            $this->currencyCode,
            $this->preferredExecutionMode
        );
    }

    /**
     * @return list<string>
     */
    public function candidateLookupKeys(): array
    {
        if ($this->decisionKey !== null && trim($this->decisionKey) !== '') {
            return [trim($this->decisionKey)];
        }

        $marketCandidates = [$this->marketId, null];
        $currencyCandidates = [$this->currencyCode, null];
        $executionModeCandidates = [$this->preferredExecutionMode, null];
        $keys = [];

        foreach ($marketCandidates as $marketId) {
            foreach ($currencyCandidates as $currencyCode) {
                foreach ($executionModeCandidates as $executionMode) {
                    $keys[] = $this->composeLookupKey($marketId, $currencyCode, $executionMode);
                }
            }
        }

        return array_values(array_unique($keys));
    }

    private function composeLookupKey(?int $marketId, ?string $currencyCode, ?ExecutionMode $executionMode): string
    {
        return implode(':', [
            $this->surface->value,
            $marketId ?? 'any',
            $currencyCode ? strtolower($currencyCode) : 'any',
            $executionMode?->value ?? 'any',
        ]);
    }
}
