<?php

namespace App\Billing\Support;

use App\Billing\Providers\ProviderDefinition;
use App\Models\BillingRoutingDecision;
use App\Models\Payment;
use App\Services\PaymentLinkService;

class BillingRoutingDecisionRecorder
{
    public function recordWalletFunding(Payment $payment, array $context, array $options = []): BillingRoutingDecision
    {
        if ($existing = $this->existingPinnedDecision($payment)) {
            return $existing;
        }

        $providerKey = strtolower(trim((string) ($context['provider_key'] ?? $context['provider'] ?? $payment->provider_key ?? '')));
        $providerDefinition = $context['provider_definition'] ?? null;
        $environment = strtolower(trim((string) ($context['environment'] ?? $payment->provider_environment ?? 'sandbox')));
        $executionMode = $this->executionModeFor($providerKey, $context, $providerDefinition);
        $snapshot = $this->walletFundingSnapshot($payment, $context, $options, $providerDefinition, $providerKey, $environment, $executionMode);

        return BillingRoutingDecision::query()->create([
            'payment_id' => (int) $payment->id,
            'market_id' => (int) $payment->platform_id,
            'billing_surface' => BillingSurface::WalletFunding->value,
            'chosen_binding_id' => $context['chosen_binding_id'] ?? null,
            'provider_profile_id' => $context['provider_profile_id'] ?? null,
            'provider_type_key' => $providerDefinition?->key ?? $providerKey,
            'execution_mode' => $executionMode,
            'environment' => $environment,
            'fallback_taken' => false,
            'decision_version' => 1,
            'shadow_diff_json' => null,
            'surface_cutover_flag' => null,
            'snapshot_json' => $snapshot,
            'immutable_until_terminal_state' => true,
            'decision_json' => [
                'source' => 'wallet_topup_initiation',
                'provider_key' => $providerKey,
                'provider_alias' => $providerKey !== ($providerDefinition?->key ?? $providerKey) ? $providerKey : null,
                'transport' => data_get($context, 'provider_credentials.transport'),
                'provider_resolved_from' => $context['provider_resolved_from'] ?? null,
                'request_has_callback_override' => trim((string) ($options['callback_url'] ?? '')) !== '',
            ],
            'created_at' => now(),
        ]);
    }

    public function recordPaymentLink(Payment $payment, array $resolvedProvider, string $paymentUrl, array $options = []): BillingRoutingDecision
    {
        if ($existing = $this->existingPinnedDecision($payment)) {
            return $existing;
        }

        $providerKey = strtolower(trim((string) ($resolvedProvider['key'] ?? $payment->provider_key ?? 'payment_link')));
        $providerConfig = is_array($resolvedProvider['config'] ?? null) ? $resolvedProvider['config'] : [];
        $providerTypeKey = strtolower(trim((string) ($providerConfig['wallet_provider_key'] ?? $providerKey)));
        $mode = strtolower(trim((string) ($providerConfig['mode'] ?? PaymentLinkService::MODE_STATIC_URL)));
        $environment = strtolower(trim((string) ($providerConfig['environment'] ?? $payment->provider_environment ?? 'production')));
        $surface = $mode === PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT
            ? BillingSurface::ProxyHostedCheckout
            : BillingSurface::SubscriptionLink;
        $executionMode = $mode === PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT
            ? ExecutionMode::Proxy->value
            : ExecutionMode::Direct->value;
        $executionFamily = $mode === PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT
            ? 'hosted_redirect'
            : 'subscription_link';

        return BillingRoutingDecision::query()->create([
            'payment_id' => (int) $payment->id,
            'market_id' => (int) $payment->platform_id,
            'billing_surface' => $surface->value,
            'chosen_binding_id' => null,
            'provider_profile_id' => null,
            'provider_type_key' => $providerTypeKey,
            'execution_mode' => $executionMode,
            'environment' => $environment,
            'fallback_taken' => false,
            'decision_version' => 1,
            'shadow_diff_json' => null,
            'surface_cutover_flag' => null,
            'snapshot_json' => [
                'payment_id' => (int) $payment->id,
                'payment_purpose' => (string) ($payment->purpose ?: 'subscription'),
                'billing_surface' => $surface->value,
                'provider_key' => $providerKey,
                'provider_type_key' => $providerTypeKey,
                'provider_label' => (string) ($providerConfig['label'] ?? $providerKey),
                'provider_family' => $mode === PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT ? 'hosted_checkout' : 'static_link',
                'execution_family' => $executionFamily,
                'environment' => $environment,
                'execution_mode' => $executionMode,
                'callback_contract' => [
                    'type' => 'browser_completion',
                    'path' => $paymentUrl,
                ],
                'pricing' => [
                    'amount' => number_format((float) $payment->amount, 2, '.', ''),
                    'currency' => (string) $payment->currency,
                ],
                'fx_quote' => [
                    'mode' => 'same_currency',
                    'quote_locked' => true,
                    'market_currency' => (string) ($payment->platform?->currency_code ?: $payment->currency),
                    'payment_currency' => (string) $payment->currency,
                ],
                'link_mode' => $mode,
            ],
            'immutable_until_terminal_state' => true,
            'decision_json' => [
                'source' => 'payment_link_send',
                'provider_key' => $providerKey,
                'requested_provider' => $options['requested_provider'] ?? $providerKey,
                'notification_purpose' => $options['notification_purpose'] ?? null,
            ],
            'created_at' => now(),
        ]);
    }

    public function recordSelfCheckout(
        Payment $payment,
        array $context,
        array $resolvedProvider,
        array $pricing,
        string $paymentUrl
    ): BillingRoutingDecision {
        if ($existing = $this->existingPinnedDecision($payment)) {
            return $existing;
        }

        $providerConfig = is_array($resolvedProvider['config'] ?? null) ? $resolvedProvider['config'] : [];
        $providerKey = strtolower(trim((string) ($context['provider_key'] ?? $payment->provider_key ?? '')));
        $providerTypeKey = strtolower(trim((string) ($providerConfig['wallet_provider_key'] ?? $providerKey)));
        $providerMode = strtolower(trim((string) ($providerConfig['mode'] ?? PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT)));
        $environment = strtolower(trim((string) ($context['environment'] ?? $payment->provider_environment ?? 'production')));
        $executionMode = $providerMode === PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT
            ? ExecutionMode::Proxy->value
            : ExecutionMode::Direct->value;

        return BillingRoutingDecision::query()->create([
            'payment_id' => (int) $payment->id,
            'market_id' => (int) $payment->platform_id,
            'billing_surface' => BillingSurface::SelfCheckout->value,
            'chosen_binding_id' => null,
            'provider_profile_id' => null,
            'provider_type_key' => $providerTypeKey,
            'execution_mode' => $executionMode,
            'environment' => $environment,
            'fallback_taken' => false,
            'decision_version' => 1,
            'shadow_diff_json' => null,
            'surface_cutover_flag' => null,
            'snapshot_json' => [
                'payment_id' => (int) $payment->id,
                'payment_purpose' => (string) ($payment->purpose ?: 'subscription'),
                'billing_surface' => BillingSurface::SelfCheckout->value,
                'provider_key' => $providerKey,
                'provider_type_key' => $providerTypeKey,
                'provider_label' => (string) ($providerConfig['label'] ?? $providerKey),
                'provider_family' => 'hosted_checkout',
                'execution_family' => 'hosted_redirect',
                'environment' => $environment,
                'execution_mode' => $executionMode,
                'callback_contract' => [
                    'type' => 'browser_completion',
                    'path' => $paymentUrl,
                ],
                'pricing' => [
                    'amount' => number_format((float) ($pricing['amount'] ?? $payment->amount), 2, '.', ''),
                    'currency' => (string) ($pricing['currency'] ?? $payment->currency),
                    'quoted_amount' => $pricing['quoted_amount'] ?? null,
                    'quoted_currency' => $pricing['quoted_currency'] ?? null,
                ],
                'fx_quote' => [
                    'mode' => ((bool) data_get($pricing, 'fx_override.applied', false)) ? 'fixed_override' : 'same_currency',
                    'quote_locked' => true,
                    'market_currency' => (string) ($pricing['quoted_currency'] ?? $payment->platform?->currency_code ?: $payment->currency),
                    'payment_currency' => (string) ($pricing['currency'] ?? $payment->currency),
                    'fx_override' => $pricing['fx_override'] ?? null,
                ],
                'provider_mode' => $providerMode,
                'provider_config_key' => $resolvedProvider['key'] ?? null,
            ],
            'immutable_until_terminal_state' => true,
            'decision_json' => [
                'source' => 'self_checkout_initiation',
                'provider_key' => $providerKey,
                'provider_config_key' => $resolvedProvider['key'] ?? null,
                'provider_mode' => $providerMode,
            ],
            'created_at' => now(),
        ]);
    }

    public function recordWalletSubscription(
        Payment $payment,
        array $pricing,
        array $options = []
    ): BillingRoutingDecision {
        if ($existing = $this->existingPinnedDecision($payment)) {
            return $existing;
        }

        $origin = strtolower(trim((string) ($options['origin'] ?? data_get($payment->payment_data, 'origin') ?? 'wallet_subscribe')));
        $surface = $origin === 'wallet_auto_subscribe'
            ? BillingSurface::WalletAutoRenew
            : BillingSurface::SelfCheckout;
        $environment = strtolower(trim((string) ($options['environment'] ?? $payment->provider_environment ?? 'production')));

        return BillingRoutingDecision::query()->create([
            'payment_id' => (int) $payment->id,
            'market_id' => (int) $payment->platform_id,
            'billing_surface' => $surface->value,
            'chosen_binding_id' => null,
            'provider_profile_id' => null,
            'provider_type_key' => BillingRail::WalletBalance->value,
            'execution_mode' => ExecutionMode::Direct->value,
            'environment' => $environment,
            'fallback_taken' => false,
            'decision_version' => 1,
            'shadow_diff_json' => null,
            'surface_cutover_flag' => null,
            'snapshot_json' => [
                'payment_id' => (int) $payment->id,
                'payment_purpose' => (string) ($payment->purpose ?: 'subscription'),
                'billing_surface' => $surface->value,
                'provider_key' => (string) ($payment->provider_key ?: 'wallet'),
                'provider_type_key' => BillingRail::WalletBalance->value,
                'provider_label' => 'Wallet balance',
                'provider_family' => 'internal_wallet',
                'execution_family' => 'internal_ledger',
                'environment' => $environment,
                'execution_mode' => ExecutionMode::Direct->value,
                'callback_contract' => [
                    'type' => 'internal_completion',
                    'path' => null,
                ],
                'pricing' => [
                    'amount' => number_format((float) ($pricing['amount'] ?? $payment->amount), 2, '.', ''),
                    'currency' => (string) ($pricing['currency'] ?? $payment->currency),
                    'duration_key' => $pricing['duration_key'] ?? data_get($payment->payment_data, 'duration_key'),
                    'duration_days' => $pricing['duration_days'] ?? data_get($payment->payment_data, 'duration_days'),
                    'duration_label' => $pricing['duration_label'] ?? data_get($payment->payment_data, 'duration_label'),
                ],
                'fx_quote' => [
                    'mode' => 'same_currency',
                    'quote_locked' => true,
                    'market_currency' => (string) ($payment->platform?->currency_code ?: $pricing['currency'] ?? $payment->currency),
                    'payment_currency' => (string) ($pricing['currency'] ?? $payment->currency),
                ],
                'rail' => BillingRail::WalletBalance->value,
                'wallet_policy' => [
                    'origin' => $origin,
                    'topup_payment_id' => $options['topup_payment_id'] ?? data_get($payment->payment_data, 'topup_payment_id'),
                    'idempotency_key' => $options['idempotency_key'] ?? data_get($payment->payment_data, 'idempotency_key'),
                ],
            ],
            'immutable_until_terminal_state' => true,
            'decision_json' => [
                'source' => 'wallet_subscription_initiation',
                'origin' => $origin,
                'trigger' => $origin === 'wallet_auto_subscribe' ? 'auto_renew' : 'self_service',
                'topup_payment_id' => $options['topup_payment_id'] ?? data_get($payment->payment_data, 'topup_payment_id'),
            ],
            'created_at' => now(),
        ]);
    }

    private function walletFundingSnapshot(
        Payment $payment,
        array $context,
        array $options,
        ?ProviderDefinition $providerDefinition,
        string $providerKey,
        string $environment,
        string $executionMode
    ): array {
        $providerConfig = is_array($context['provider_config'] ?? null) ? $context['provider_config'] : [];
        $walletConfig = is_array($context['wallet'] ?? null) ? $context['wallet'] : [];
        $callbackUrl = trim((string) ($options['callback_url'] ?? ''));
        $transport = trim((string) data_get($context, 'provider_credentials.transport', ''));

        $executionFamily = $this->executionFamilyFor($providerKey, $providerDefinition);
        $usesWebhookCallback = $executionFamily === 'mobile_collection';

        if ($callbackUrl === '') {
            $callbackUrl = $usesWebhookCallback
                ? '/api/billing/mpesa/callback'
                : '/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid);
        }

        return [
            'payment_id' => (int) $payment->id,
            'payment_purpose' => (string) $payment->purpose,
            'billing_surface' => BillingSurface::WalletFunding->value,
            'provider_key' => $providerKey,
            'provider_type_key' => $providerDefinition?->key ?? $providerKey,
            'provider_label' => $providerDefinition?->label,
            'provider_family' => $providerDefinition?->family->value,
            'execution_family' => $executionFamily,
            'environment' => $environment,
            'execution_mode' => $executionMode,
            'callback_contract' => [
                'type' => $usesWebhookCallback ? 'webhook' : 'browser_completion',
                'path' => $callbackUrl,
            ],
            'pricing' => [
                'amount' => number_format((float) $payment->amount, 2, '.', ''),
                'currency' => (string) $payment->currency,
                'requested_amount' => data_get($payment->payment_data, 'requested_amount'),
                'topup_limits' => [
                    'provider_min' => $providerConfig['min_amount'] ?? null,
                    'provider_max' => $providerConfig['max_amount'] ?? null,
                    'market_max_balance' => $walletConfig['max_wallet_balance'] ?? null,
                ],
            ],
            'fx_quote' => [
                'mode' => 'same_currency',
                'quote_locked' => true,
                'market_currency' => (string) ($walletConfig['currency_code'] ?? $payment->currency),
                'payment_currency' => (string) $payment->currency,
            ],
            'transport' => $transport !== '' ? $transport : null,
            'wallet_policy' => [
                'auto_subscribe' => data_get($payment->payment_data, 'auto_subscribe'),
            ],
        ];
    }

    private function executionModeFor(string $providerKey, array $context, ?ProviderDefinition $providerDefinition): string
    {
        $transport = strtolower(trim((string) data_get($context, 'provider_credentials.transport', '')));

        if (in_array($providerKey, ['mpesa_stk', 'daraja', 'kopokopo'], true)) {
            return $transport === 'direct_provider'
                ? ExecutionMode::Direct->value
                : ExecutionMode::Transitional->value;
        }

        if ($providerDefinition?->supportsExecutionMode(ExecutionMode::Proxy) && (bool) data_get($context, 'provider_config.proxy_enabled', false)) {
            return ExecutionMode::Proxy->value;
        }

        return ExecutionMode::Direct->value;
    }

    private function executionFamilyFor(string $providerKey, ?ProviderDefinition $providerDefinition): string
    {
        if (in_array($providerKey, ['mpesa_stk', 'daraja', 'kopokopo'], true)) {
            return 'mobile_collection';
        }

        return match ($providerDefinition?->family) {
            ProviderFamily::Daraja, ProviderFamily::Kopokopo => 'mobile_collection',
            ProviderFamily::Nowpayments => 'crypto_invoice',
            default => 'hosted_redirect',
        };
    }

    private function existingPinnedDecision(Payment $payment): ?BillingRoutingDecision
    {
        return $payment->routingDecisions()
            ->where('immutable_until_terminal_state', true)
            ->latest('id')
            ->first();
    }
}
