<?php

namespace App\Contracts;

use App\Models\Payment;

/**
 * Provider-agnostic execution interface for payment routing.
 *
 * Instead of branching on provider key in controllers/services,
 * routing logic is encapsulated in executor implementations that:
 * 1. Accept a Payment + runtime billing config
 * 2. Resolve the provider-specific handler
 * 3. Execute and return normalized action results
 *
 * This allows hosted checkout, STK, wallet, and subscription initiation
 * to share a common routing abstraction without repeating provider branching.
 */
interface ProviderRoutingExecutor
{
    /**
     * Execute provider-specific routing logic.
     *
     * @param Payment $payment The payment being routed
     * @param array $context Runtime billing configuration (provider config, credentials, environment, etc.)
     * @param array $options Provider-specific options (callback URLs, metadata, descriptions, etc.)
     * @return array Normalized action result with 'type', 'url', 'provider_reference', etc.
     * @throws \RuntimeException If provider is unsupported or initialization fails
     */
    public function execute(Payment $payment, array $context, array $options = []): array;

    /**
     * Check if this executor supports the given provider.
     *
     * @param string $providerKey The provider identifier
     * @return bool
     */
    public function supports(string $providerKey): bool;
}
