<?php

namespace App\Services\Routing;

use App\Contracts\ProviderRoutingExecutor;
use App\Models\Payment;
use App\Services\HostedCheckoutService;

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
    private const SUPPORTED_PROVIDERS = ['paystack', 'pesapal'];

    public function __construct(
        private readonly HostedCheckoutService $hostedCheckoutService
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

        return match ($providerKey) {
            'paystack' => $this->hostedCheckoutService->initializePaystack($payment, $context, $options),
            'pesapal' => $this->hostedCheckoutService->initializePesapal($payment, $context, $options),
        };
    }

    public function supports(string $providerKey): bool
    {
        return in_array($providerKey, self::SUPPORTED_PROVIDERS, true);
    }
}
