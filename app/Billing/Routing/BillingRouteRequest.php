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

        return implode(':', [
            $this->surface->value,
            $this->marketId ?? 'any',
            $this->currencyCode ? strtolower($this->currencyCode) : 'any',
            $this->preferredExecutionMode?->value ?? 'any',
        ]);
    }
}
