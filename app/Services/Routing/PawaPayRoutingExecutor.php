<?php

namespace App\Services\Routing;

use App\Contracts\ProviderRoutingExecutor;
use App\Models\{Payment, PayLinkCharge};
use App\Billing\Providers\PawaPay\PawaPayDirectDepositAdapter;

class PawaPayRoutingExecutor implements ProviderRoutingExecutor
{
    public function __construct(private readonly HostedCheckoutRoutingExecutor $hosted, private readonly PawaPayDirectDepositAdapter $direct) {}

    public function supports(string $providerKey): bool { return $providerKey === 'pawapay'; }

    public function execute(Payment $payment, array $context, array $options = []): array
    {
        if (($context['billing_surface'] ?? '') !== 'pay_link') return $this->hosted->execute($payment, $context, $options);
        return $this->direct->initiate(PayLinkCharge::where('payment_id', $payment->id)->firstOrFail());
    }
}
