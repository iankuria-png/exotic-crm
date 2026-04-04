<?php

namespace App\Billing\Support;

use App\Billing\Providers\ProviderDefinition;
use App\Models\BillingRoutingDecision;
use App\Models\Payment;

class BillingRoutingDecisionRecorder
{
    public function recordWalletFunding(Payment $payment, array $context, array $options = []): BillingRoutingDecision
    {
        $providerKey = strtolower(trim((string) ($context['provider_key'] ?? $context['provider'] ?? $payment->provider_key ?? '')));
        $providerDefinition = $context['provider_definition'] ?? null;
        $environment = strtolower(trim((string) ($context['environment'] ?? $payment->provider_environment ?? 'sandbox')));
        $executionMode = $this->executionModeFor($providerKey, $context, $providerDefinition);
        $snapshot = $this->walletFundingSnapshot($payment, $context, $options, $providerDefinition, $providerKey, $environment, $executionMode);

        return BillingRoutingDecision::query()->create([
            'payment_id' => (int) $payment->id,
            'market_id' => (int) $payment->platform_id,
            'billing_surface' => BillingSurface::WalletFunding->value,
            'chosen_binding_id' => null,
            'provider_profile_id' => null,
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
                'request_has_callback_override' => trim((string) ($options['callback_url'] ?? '')) !== '',
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

        if ($callbackUrl === '') {
            $callbackUrl = $providerKey === 'mpesa_stk'
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
            'execution_family' => $this->executionFamilyFor($providerKey, $providerDefinition),
            'environment' => $environment,
            'execution_mode' => $executionMode,
            'callback_contract' => [
                'type' => $providerKey === 'mpesa_stk' ? 'webhook' : 'browser_completion',
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

        if ($providerKey === 'mpesa_stk') {
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
        if ($providerKey === 'mpesa_stk') {
            return 'mobile_collection';
        }

        return match ($providerDefinition?->family) {
            ProviderFamily::Nowpayments => 'crypto_invoice',
            default => 'hosted_redirect',
        };
    }
}
