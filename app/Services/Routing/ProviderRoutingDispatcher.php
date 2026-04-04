<?php

namespace App\Services\Routing;

use App\Contracts\ProviderRoutingExecutor;
use App\Models\Payment;
use InvalidArgumentException;

/**
 * Provider routing dispatcher.
 *
 * Central routing hub that accepts a provider key and dispatches
 * to the appropriate executor (hosted checkout, STK, wallet, subscription, etc).
 *
 * This replaces scattered match($provider) statements across the codebase
 * with a single, extensible routing point. New payment methods only need
 * a new executor implementation registered here.
 *
 * Usage:
 *   $action = $dispatcher->dispatch($payment, $context, $options);
 */
class ProviderRoutingDispatcher
{
    /**
     * @var array<string, ProviderRoutingExecutor> Registered executors by provider
     */
    private array $executors = [];

    /**
     * Register a routing executor for a provider.
     *
     * @param ProviderRoutingExecutor $executor
     * @param string ...$providers Provider keys this executor handles
     */
    public function register(ProviderRoutingExecutor $executor, string ...$providers): void
    {
        foreach ($providers as $provider) {
            $this->executors[$provider] = $executor;
        }
    }

    /**
     * Dispatch to the appropriate executor based on provider key.
     *
     * @param Payment $payment
     * @param array $context Runtime billing configuration
     * @param array $options Executor-specific options
     * @return array Normalized action result
     * @throws InvalidArgumentException If no executor handles the provider
     */
    public function dispatch(Payment $payment, array $context, array $options = []): array
    {
        $providerKey = $context['provider_key'] ?? null;

        if (!$providerKey || !isset($this->executors[$providerKey])) {
            throw new InvalidArgumentException(
                "No routing executor registered for provider: {$providerKey}"
            );
        }

        return $this->executors[$providerKey]->execute($payment, $context, $options);
    }

    /**
     * Check if a provider is registered.
     *
     * @param string $providerKey
     * @return bool
     */
    public function supports(string $providerKey): bool
    {
        return isset($this->executors[$providerKey]);
    }

    /**
     * Get all registered providers.
     *
     * @return array<string>
     */
    public function registeredProviders(): array
    {
        return array_keys($this->executors);
    }
}
