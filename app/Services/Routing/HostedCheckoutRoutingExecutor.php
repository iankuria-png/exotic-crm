<?php

namespace App\Services\Routing;

use App\Contracts\ProviderRoutingExecutor;
use App\Models\Payment;
use App\Services\BillingGatewayService;
use Illuminate\Http\Request;

/**
 * Hosted checkout routing executor.
 *
 * Handles Paystack and Pesapal checkout initialization through
 * HostedCheckoutService, removing provider branching from controllers.
 *
 * Usage in PaymentLinkProxyController:
 *   $executor = new HostedCheckoutRoutingExecutor($this->hostedCheckoutService);
 *   $action = $executor->execute($payment, $context, $options);
 */
class HostedCheckoutRoutingExecutor implements ProviderRoutingExecutor
{
    private const SUPPORTED_PROVIDERS = ['paystack', 'pesapal', 'pawapay'];

    public function __construct(
        private readonly BillingGatewayService $billingGatewayService
    ) {
    }

    public function execute(Payment $payment, array $context, array $options = []): array
    {
        $providerKey = $context['provider_key'] ?? null;

        if (!$this->supports($providerKey)) {
            throw new \InvalidArgumentException(
                "Hosted checkout does not support provider: {$providerKey}. Supported: " . implode(', ', self::SUPPORTED_PROVIDERS)
            );
        }

        $request = $options['request'] ?? null;

        return $this->billingGatewayService->initiateHostedCheckoutForRouting(
            $payment,
            $context,
            $options,
            $request instanceof Request ? $request : null
        );
    }

    public function supports(string $providerKey): bool
    {
        return in_array($providerKey, self::SUPPORTED_PROVIDERS, true);
    }
}
