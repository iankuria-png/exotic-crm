<?php

namespace App\Billing\Diagnostics;

final class BillingDiagnosticsView
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int, array<string, mixed>>  $sections
     */
    public function __construct(
        public readonly ?int $marketId = null,
        public readonly ?string $providerKey = null,
        public readonly string $source = 'billing_namespace_skeleton',
        public readonly array $sections = [],
        public readonly array $meta = []
    ) {}
}
