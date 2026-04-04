<?php

namespace App\Services\Routing;

use App\Contracts\ProviderRoutingExecutor;
use App\Models\Payment;
use App\Services\BillingGatewayService;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * M-Pesa STK routing executor.
 *
 * Handles M-Pesa STK push initialization through BillingGatewayService,
 * removing STK-specific branching from controllers and other services.
 *
 * Usage in wallet topup flows:
 *   $executor = new MpesaStkRoutingExecutor($this->billingGatewayService);
 *   $action = $executor->execute($payment, $context, ['phone' => '+254701234567']);
 */
class MpesaStkRoutingExecutor implements ProviderRoutingExecutor
{
    private const SUPPORTED_PROVIDERS = ['mpesa_stk'];

    public function __construct(
        private readonly BillingGatewayService $billingGatewayService
    ) {
    }

    public function execute(Payment $payment, array $context, array $options = []): array
    {
        $providerKey = $context['provider_key'] ?? null;

        if (!$this->supports($providerKey)) {
            throw new InvalidArgumentException(
                "STK routing does not support provider: {$providerKey}. Supported: " . implode(', ', self::SUPPORTED_PROVIDERS)
            );
        }

        // Delegate to BillingGatewayService's internal STK method
        // The service handles transport selection (direct_provider vs proxies)
        // and returns normalized STK action response
        $request = $options['request'] ?? null;

        return $this->billingGatewayService->initiateStkForRouting(
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
