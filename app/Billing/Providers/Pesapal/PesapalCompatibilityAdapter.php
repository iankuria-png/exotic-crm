<?php

namespace App\Billing\Providers\Pesapal;

use App\Models\Payment;
use App\Services\HostedCheckoutService;

class PesapalCompatibilityAdapter
{
    public function __construct(
        private readonly HostedCheckoutService $hostedCheckoutService
    ) {
    }

    public function initialize(Payment $payment, array $context, array $options = []): array
    {
        return $this->hostedCheckoutService->initializePesapal($payment, $context, $options);
    }

    public function verify(Payment $payment, array $context, string $trackingId): array
    {
        return $this->hostedCheckoutService->verifyPesapalTransaction($payment, $context, $trackingId);
    }
}
