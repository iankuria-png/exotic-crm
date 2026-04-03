<?php

namespace App\Billing\Diagnostics;

use App\Billing\Contracts\BillingDiagnosticsAssembler as BillingDiagnosticsAssemblerContract;

class BillingDiagnosticsAssembler implements BillingDiagnosticsAssemblerContract
{
    public function assembleBilling(?int $marketId = null, ?string $providerKey = null): BillingDiagnosticsView
    {
        return new BillingDiagnosticsView(
            marketId: $marketId,
            providerKey: $providerKey,
            sections: [],
            meta: []
        );
    }

    public function assemblePayment(int $paymentId): PaymentDiagnosticsView
    {
        return new PaymentDiagnosticsView(
            paymentId: $paymentId,
            sections: [],
            meta: []
        );
    }
}
