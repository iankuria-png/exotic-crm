<?php

namespace App\Billing\Contracts;

use App\Billing\Diagnostics\BillingDiagnosticsView;
use App\Billing\Diagnostics\PaymentDiagnosticsView;

interface BillingDiagnosticsAssembler
{
    public function assembleBilling(?int $marketId = null, ?string $providerKey = null): BillingDiagnosticsView;

    public function assemblePayment(int $paymentId): PaymentDiagnosticsView;
}
