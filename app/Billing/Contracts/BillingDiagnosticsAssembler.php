<?php

namespace App\Billing\Contracts;

use App\Billing\Diagnostics\BillingDiagnosticsView;
use App\Billing\Diagnostics\PaymentDiagnosticsView;

interface BillingDiagnosticsAssembler
{
    /**
     * @param  array<int, int>|null  $visibleMarketIds
     */
    public function assembleBilling(?int $marketId = null, ?string $providerKey = null, ?array $visibleMarketIds = null): BillingDiagnosticsView;

    public function assemblePayment(int $paymentId): PaymentDiagnosticsView;
}
