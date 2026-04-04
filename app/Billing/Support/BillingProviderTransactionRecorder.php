<?php

namespace App\Billing\Support;

use App\Models\BillingProviderTransaction;
use App\Models\BillingRoutingDecision;
use App\Models\Payment;

class BillingProviderTransactionRecorder
{
    public function recordInitiation(Payment $payment, array $context, array $action, array $options = []): BillingProviderTransaction
    {
        $decision = $this->latestPinnedDecision($payment);
        $providerTypeKey = $this->resolveProviderTypeKey($payment, $context, $decision);
        $attemptGroupKey = $this->resolveAttemptGroupKey($payment, $providerTypeKey);
        $attemptSequence = $this->nextAttemptSequence($payment, $attemptGroupKey);
        $providerReference = trim((string) ($action['provider_reference'] ?? ''));

        return BillingProviderTransaction::query()->create([
            'payment_id' => (int) $payment->id,
            'provider_type_key' => $providerTypeKey,
            'provider_profile_id' => $decision?->provider_profile_id,
            'normalized_status' => 'pending',
            'provider_transaction_id' => $providerReference !== '' ? $providerReference : null,
            'provider_session_id' => $this->providerSessionId($providerTypeKey, $providerReference),
            'provider_invoice_id' => $this->providerInvoiceId($providerTypeKey, $providerReference),
            'provider_status' => 'initiated',
            'requested_amount' => $payment->amount,
            'requested_currency' => $payment->currency,
            'charge_amount' => $payment->amount,
            'charge_currency' => $payment->currency,
            'confirmation_state_json' => array_filter([
                'reason_code' => (string) ($options['reason_code'] ?? 'initial_initiation'),
                'billing_surface' => $decision?->billing_surface,
                'execution_mode' => $decision?->execution_mode,
            ], static fn ($value) => $value !== null && $value !== ''),
            'upstream_reference_json' => array_filter([
                'payment_reference_number' => $payment->reference_number,
                'payment_transaction_reference' => $payment->transaction_reference,
                'provider_reference' => $providerReference !== '' ? $providerReference : null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'attempt_group_key' => $attemptGroupKey,
            'attempt_sequence' => $attemptSequence,
            'retry_of_provider_transaction_id' => isset($options['retry_of_provider_transaction_id'])
                ? (int) $options['retry_of_provider_transaction_id']
                : null,
            'fallback_from_provider_transaction_id' => isset($options['fallback_from_provider_transaction_id'])
                ? (int) $options['fallback_from_provider_transaction_id']
                : null,
            'compatibility_reference' => $providerReference !== '' ? $providerReference : ($payment->transaction_reference ?: $payment->reference_number),
            'state_version' => 1,
            'raw_state_json' => [
                'action' => $action,
                'context_provider_key' => $context['provider_key'] ?? $context['provider'] ?? null,
                'recorded_at' => now()->toIso8601String(),
            ],
            'last_status_at' => now(),
        ]);
    }

    public function latestAttempt(Payment $payment, ?string $providerTypeKey = null): ?BillingProviderTransaction
    {
        return BillingProviderTransaction::query()
            ->where('payment_id', (int) $payment->id)
            ->when($providerTypeKey !== null && $providerTypeKey !== '', function ($query) use ($providerTypeKey) {
                $query->where('provider_type_key', $providerTypeKey);
            })
            ->latest('id')
            ->first();
    }

    private function latestPinnedDecision(Payment $payment): ?BillingRoutingDecision
    {
        return $payment->routingDecisions()
            ->where('immutable_until_terminal_state', true)
            ->latest('id')
            ->first();
    }

    private function resolveProviderTypeKey(Payment $payment, array $context, ?BillingRoutingDecision $decision): string
    {
        return strtolower(trim((string) (
            $decision?->provider_type_key
            ?? data_get($context, 'provider_definition.key')
            ?? $context['provider_key']
            ?? $context['provider']
            ?? $payment->provider_key
            ?? 'unknown'
        )));
    }

    private function resolveAttemptGroupKey(Payment $payment, string $providerTypeKey): string
    {
        $existing = $this->latestAttempt($payment, $providerTypeKey);
        if ($existing && trim((string) $existing->attempt_group_key) !== '') {
            return (string) $existing->attempt_group_key;
        }

        return 'payment:' . (int) $payment->id . ':provider:' . $providerTypeKey;
    }

    private function nextAttemptSequence(Payment $payment, string $attemptGroupKey): int
    {
        $latestSequence = BillingProviderTransaction::query()
            ->where('payment_id', (int) $payment->id)
            ->where('attempt_group_key', $attemptGroupKey)
            ->max('attempt_sequence');

        return max(1, ((int) $latestSequence) + 1);
    }

    private function providerSessionId(string $providerTypeKey, string $providerReference): ?string
    {
        return $providerTypeKey === 'mpesa_stk' && $providerReference !== ''
            ? $providerReference
            : null;
    }

    private function providerInvoiceId(string $providerTypeKey, string $providerReference): ?string
    {
        return in_array($providerTypeKey, ['paystack', 'pesapal'], true) && $providerReference !== ''
            ? $providerReference
            : null;
    }
}
