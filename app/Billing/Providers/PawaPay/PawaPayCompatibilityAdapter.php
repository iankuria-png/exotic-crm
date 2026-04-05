<?php

namespace App\Billing\Providers\PawaPay;

use App\Models\Payment;
use App\Services\HostedCheckoutService;

class PawaPayCompatibilityAdapter
{
    public function __construct(
        private readonly HostedCheckoutService $hostedCheckoutService
    ) {
    }

    public function initialize(Payment $payment, array $context, array $options = []): array
    {
        return $this->hostedCheckoutService->initializePawaPay($payment, $context, $options);
    }

    public function verify(Payment $payment, array $context, string $depositId): array
    {
        return $this->hostedCheckoutService->verifyPawaPayDeposit($payment, $context, $depositId);
    }
}
