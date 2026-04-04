<?php

namespace App\Services\Routing;

use App\Contracts\ProviderRoutingExecutor;
use App\Models\Payment;

/**
 * Subscription routing executor.
 *
 * Handles subscription initiation and renewal payment processing.
 * Routes to the appropriate payment method executor (hosted checkout, STK, etc)
 * based on subscription configuration and market settings.
 *
 * Subscriptions can be funded through any payment method, so this executor
 * normalizes routing for subscription-specific payment flows.
 *
 * Usage in subscription renewal flows:
 *   $executor = new SubscriptionRoutingExecutor($this->dispatchedService);
 *   $action = $executor->execute($payment, $context, $options);
 */
class SubscriptionRoutingExecutor implements ProviderRoutingExecutor
{
    public function __construct(
        private readonly ProviderRoutingDispatcher $dispatcher
    ) {
    }

    /**
     * Execute subscription payment routing.
     *
     * Subscriptions route through the same providers as topup,
     * but with subscription-specific options (auto-renew, frequency, etc).
     */
    public function execute(Payment $payment, array $context, array $options = []): array
    {
        $providerKey = $context['provider_key'] ?? null;

        if (!$this->supports($providerKey)) {
            throw new \InvalidArgumentException(
                "Subscription routing does not support provider: {$providerKey}"
            );
        }

        // Subscriptions add context about auto-renew and renewal schedule
        $subscriptionContext = array_merge($context, [
            'surface' => 'subscription',
            'auto_renew' => $options['auto_renew'] ?? true,
            'renewal_frequency_days' => $options['renewal_frequency_days'] ?? null,
        ]);

        // Route through dispatcher which delegates to appropriate executor
        return $this->dispatcher->dispatch($payment, $subscriptionContext, $options);
    }

    /**
     * Subscriptions support any provider the dispatcher supports.
     */
    public function supports(string $providerKey): bool
    {
        return $this->dispatcher->supports($providerKey);
    }
}
