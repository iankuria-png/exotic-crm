<?php

namespace App\Billing\Support;

use App\Models\Payment;

class CanonicalPaymentStateReducer
{
    public function complete(Payment $payment, array $context = []): array
    {
        $paymentData = $this->normalizePaymentData($context['payment_data'] ?? $payment->payment_data ?? []);

        return [
            'status' => 'completed',
            'failure_reason' => null,
            'completed_at' => $payment->completed_at ?? now(),
            'payment_data' => $this->mergeCanonicalState($paymentData, [
                'payment_intent_status' => 'completed',
                'wallet_funding_status' => $this->resolveWalletFundingStatus($payment, $context),
                'provisioning_status' => $this->resolveProvisioningStatus($payment, $context),
                'transition' => (string) ($context['transition'] ?? $this->defaultCompletionTransition($payment, $context)),
                'sandbox_suppressed' => (bool) ($context['sandbox_suppressed'] ?? false),
            ]),
        ];
    }

    public function fail(Payment $payment, string $reason, array $context = []): array
    {
        $paymentData = $this->normalizePaymentData($context['payment_data'] ?? $payment->payment_data ?? []);

        return [
            'status' => 'failed',
            'failure_reason' => mb_substr($reason, 0, 190),
            'payment_data' => $this->mergeCanonicalState($paymentData, [
                'payment_intent_status' => 'failed',
                'wallet_funding_status' => $this->resolveWalletFundingStatus($payment, $context, failing: true),
                'provisioning_status' => $this->resolveProvisioningStatus($payment, $context, failing: true),
                'transition' => (string) ($context['transition'] ?? $this->defaultFailureTransition($payment, $context)),
                'sandbox_suppressed' => (bool) ($context['sandbox_suppressed'] ?? false),
            ]),
        ];
    }

    public function reviewSettlement(Payment $payment, array $context = []): array
    {
        $paymentData = $this->normalizePaymentData($context['payment_data'] ?? $payment->payment_data ?? []);
        $intentStatus = (string) ($context['payment_intent_status'] ?? 'underpaid');

        return [
            'status' => 'pending',
            'failure_reason' => null,
            'completed_at' => null,
            'payment_data' => $this->mergeCanonicalState($paymentData, [
                'payment_intent_status' => $intentStatus,
                'wallet_funding_status' => $context['wallet_funding_status'] ?? null,
                'provisioning_status' => $context['provisioning_status'] ?? null,
                'transition' => (string) ($context['transition'] ?? 'settlement_review_required'),
                'sandbox_suppressed' => (bool) ($context['sandbox_suppressed'] ?? false),
            ]),
        ];
    }

    private function mergeCanonicalState(array $paymentData, array $state): array
    {
        $paymentData['canonical_state'] = array_filter(array_merge(
            is_array($paymentData['canonical_state'] ?? null) ? $paymentData['canonical_state'] : [],
            $state,
            [
                'reducer_version' => 1,
                'reduced_at' => now()->toIso8601String(),
            ]
        ), static fn ($value) => $value !== null && $value !== '');

        return $paymentData;
    }

    private function normalizePaymentData(mixed $paymentData): array
    {
        return is_array($paymentData) ? $paymentData : [];
    }

    private function resolveWalletFundingStatus(Payment $payment, array $context, bool $failing = false): ?string
    {
        if ((string) $payment->purpose !== 'wallet_topup') {
            return null;
        }

        if (array_key_exists('wallet_funding_status', $context) && $context['wallet_funding_status'] !== null && $context['wallet_funding_status'] !== '') {
            return $context['wallet_funding_status'];
        }

        if ($failing) {
            return 'provider_failed';
        }

        return (bool) ($context['sandbox_suppressed'] ?? false) ? 'suppressed_sandbox' : 'credited';
    }

    private function resolveProvisioningStatus(Payment $payment, array $context, bool $failing = false): ?string
    {
        if ((string) $payment->purpose !== 'subscription') {
            return null;
        }

        if (array_key_exists('provisioning_status', $context) && $context['provisioning_status'] !== null && $context['provisioning_status'] !== '') {
            return $context['provisioning_status'];
        }

        if ($failing) {
            return 'not_started';
        }

        return (bool) ($context['sandbox_suppressed'] ?? false) ? 'suppressed_sandbox' : 'completed';
    }

    private function defaultCompletionTransition(Payment $payment, array $context): string
    {
        return match ((string) $payment->purpose) {
            'wallet_topup' => (bool) ($context['sandbox_suppressed'] ?? false)
                ? 'wallet_funding_sandbox_succeeded'
                : 'wallet_credit_succeeded',
            'subscription' => (bool) ($context['sandbox_suppressed'] ?? false)
                ? 'subscription_sandbox_succeeded'
                : 'subscription_provisioned',
            default => 'payment_completed',
        };
    }

    private function defaultFailureTransition(Payment $payment, array $context): string
    {
        if ((bool) ($context['sandbox_suppressed'] ?? false)) {
            return 'sandbox_provider_failed';
        }

        return match ((string) $payment->purpose) {
            'wallet_topup' => 'wallet_funding_failed',
            'subscription' => 'subscription_payment_failed',
            default => 'payment_failed',
        };
    }
}
