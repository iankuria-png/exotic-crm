<?php

namespace App\Services;

use App\Billing\Providers\Pesapal\PesapalCompatibilityAdapter;
use App\Models\BillingRoutingDecision;
use App\Models\Payment;
use InvalidArgumentException;
use RuntimeException;

class ProviderStatusQueryOrchestrator
{
    public function __construct(
        private readonly BillingModeService $billingModeService,
        private readonly HostedCheckoutService $hostedCheckoutService,
        private readonly PesapalCompatibilityAdapter $pesapalCompatibilityAdapter
    ) {
    }

    public function verify(Payment $payment, array $options = []): array
    {
        $payment->loadMissing(['platform']);

        $provider = $this->resolveProviderType($payment);
        if (!in_array($provider, ['paystack', 'pesapal'], true)) {
            throw new InvalidArgumentException('Live provider checks are available only for Paystack and Pesapal payments.');
        }

        $context = $this->billingModeService->providerContext(
            $payment->platform,
            $provider,
            requireEnabled: false,
            environmentOverride: $this->resolveEnvironment($payment)
        );

        $verification = match ($provider) {
            'paystack' => $this->hostedCheckoutService->verifyPaystackTransaction(
                $payment,
                $context,
                (string) ($options['reference'] ?? $payment->reference_number)
            ),
            'pesapal' => $this->pesapalCompatibilityAdapter->verify(
                $payment,
                $context,
                $this->resolvePesapalTrackingId($payment, $options)
            ),
        };

        $providerReference = $provider === 'pesapal'
            ? $this->resolvePesapalTrackingId($payment, $options)
            : (string) ($options['reference'] ?? $payment->transaction_reference ?: $payment->reference_number);

        return [
            'payment_id' => (int) $payment->id,
            'provider' => $provider,
            'provider_environment' => $this->resolveEnvironment($payment),
            'provider_reference' => $providerReference,
            'status' => (string) ($verification['status'] ?? 'failed'),
            'message' => $verification['message'] ?? null,
            'checked_at' => now()->toDateTimeString(),
            'data' => is_array($verification['data'] ?? null) ? $verification['data'] : [],
        ];
    }

    public function decideMutation(Payment $payment, array $verification, array $signal = []): array
    {
        $payment->loadMissing('routingDecisions');

        $resolvedProvider = $this->resolveProviderType($payment);
        $resolvedEnvironment = $this->resolveEnvironment($payment);
        $currentStatus = strtolower(trim((string) ($payment->status ?? 'pending')));
        $verificationStatus = strtolower(trim((string) ($verification['status'] ?? 'failed')));
        if (!in_array($verificationStatus, ['completed', 'failed', 'pending'], true)) {
            $verificationStatus = 'pending';
        }

        $verificationProvider = strtolower(trim((string) ($verification['provider'] ?? $resolvedProvider)));
        $verificationEnvironment = strtolower(trim((string) ($verification['provider_environment'] ?? $resolvedEnvironment)));
        $providerReference = trim((string) (
            $signal['provider_reference'] ?? null
            ?? $signal['tracking_id'] ?? null
            ?? $signal['reference'] ?? null
            ?? $verification['provider_reference'] ?? null
            ?? ''
        ));

        $expectedReference = $this->expectedProviderReference($payment, $resolvedProvider);
        $referenceMismatch = $expectedReference !== ''
            && $providerReference !== ''
            && strcasecmp($expectedReference, $providerReference) !== 0;

        if (
            $verificationProvider !== $resolvedProvider
            || $verificationEnvironment !== $resolvedEnvironment
            || $referenceMismatch
        ) {
            return [
                'decision' => 'noop_superseded',
                'reason_code' => $referenceMismatch ? 'reference_mismatch' : 'provider_contract_mismatch',
                'winning_status' => $this->winningStatus($payment),
                'verification_status' => $verificationStatus,
                'provider' => $resolvedProvider,
                'provider_environment' => $resolvedEnvironment,
                'provider_reference' => $providerReference !== '' ? $providerReference : $expectedReference,
                'terminal' => $this->isTerminalStatus($this->winningStatus($payment)),
                'superseded' => true,
                'late_signal' => false,
                'message' => 'Provider signal was ignored because it no longer matches the pinned routing contract.',
            ];
        }

        if ($this->isCompleted($payment)) {
            return [
                'decision' => 'noop_terminal',
                'reason_code' => 'completed_payment_cannot_downgrade',
                'winning_status' => 'completed',
                'verification_status' => $verificationStatus,
                'provider' => $resolvedProvider,
                'provider_environment' => $resolvedEnvironment,
                'provider_reference' => $providerReference !== '' ? $providerReference : $expectedReference,
                'terminal' => true,
                'superseded' => false,
                'late_signal' => $verificationStatus !== 'completed',
                'message' => $verificationStatus === 'completed'
                    ? 'Payment was already completed; duplicate terminal success ignored.'
                    : 'Payment was already completed; later non-success signal ignored.',
            ];
        }

        if ($currentStatus === 'failed') {
            if ($verificationStatus === 'completed') {
                return [
                    'decision' => 'apply_completed',
                    'reason_code' => 'late_success_recovery',
                    'winning_status' => 'completed',
                    'verification_status' => $verificationStatus,
                    'provider' => $resolvedProvider,
                    'provider_environment' => $resolvedEnvironment,
                    'provider_reference' => $providerReference !== '' ? $providerReference : $expectedReference,
                    'terminal' => true,
                    'superseded' => false,
                    'late_signal' => true,
                    'message' => 'Late provider success recovered a previously failed payment.',
                ];
            }

            return [
                'decision' => 'noop_terminal',
                'reason_code' => 'failed_payment_remains_terminal',
                'winning_status' => 'failed',
                'verification_status' => $verificationStatus,
                'provider' => $resolvedProvider,
                'provider_environment' => $resolvedEnvironment,
                'provider_reference' => $providerReference !== '' ? $providerReference : $expectedReference,
                'terminal' => true,
                'superseded' => false,
                'late_signal' => true,
                'message' => 'Payment already failed and no later success was verified.',
            ];
        }

        return match ($verificationStatus) {
            'completed' => [
                'decision' => 'apply_completed',
                'reason_code' => 'verified_completed',
                'winning_status' => 'completed',
                'verification_status' => $verificationStatus,
                'provider' => $resolvedProvider,
                'provider_environment' => $resolvedEnvironment,
                'provider_reference' => $providerReference !== '' ? $providerReference : $expectedReference,
                'terminal' => true,
                'superseded' => false,
                'late_signal' => false,
                'message' => 'Provider verified the payment as completed.',
            ],
            'failed' => [
                'decision' => 'apply_failed',
                'reason_code' => 'verified_failed',
                'winning_status' => 'failed',
                'verification_status' => $verificationStatus,
                'provider' => $resolvedProvider,
                'provider_environment' => $resolvedEnvironment,
                'provider_reference' => $providerReference !== '' ? $providerReference : $expectedReference,
                'terminal' => true,
                'superseded' => false,
                'late_signal' => false,
                'message' => 'Provider verified the payment as failed.',
            ],
            default => [
                'decision' => 'noop_pending',
                'reason_code' => 'verified_pending',
                'winning_status' => 'pending',
                'verification_status' => $verificationStatus,
                'provider' => $resolvedProvider,
                'provider_environment' => $resolvedEnvironment,
                'provider_reference' => $providerReference !== '' ? $providerReference : $expectedReference,
                'terminal' => false,
                'superseded' => false,
                'late_signal' => false,
                'message' => 'Provider still reports the payment as pending.',
            ],
        };
    }

    public function resolveProviderType(Payment $payment): string
    {
        return strtolower(trim((string) (
            $this->latestPinnedDecision($payment)?->provider_type_key
            ?? $payment->provider_key
            ?? ''
        )));
    }

    public function resolveEnvironment(Payment $payment): string
    {
        return strtolower(trim((string) (
            $this->latestPinnedDecision($payment)?->environment
            ?? $payment->provider_environment
            ?? 'production'
        )));
    }

    public function resolvePesapalTrackingId(Payment $payment, array $options = []): string
    {
        $trackingId = trim((string) (
            $options['tracking_id'] ?? null
            ?? $options['provider_reference'] ?? null
            ?? null
        ));

        if ($trackingId === '') {
            $trackingId = trim((string) (
            $payment->transaction_reference
            ?? data_get($payment->raw_payload, 'pesapal.order_tracking_id')
            ?? data_get($payment->payment_data, 'link_proxy.provider_reference')
            ?? ''
            ));
        }

        if ($trackingId === '') {
            throw new RuntimeException('Pesapal payment is missing a tracking id for reconciliation.');
        }

        return $trackingId;
    }

    private function latestPinnedDecision(Payment $payment): ?BillingRoutingDecision
    {
        if ($payment->relationLoaded('routingDecisions')) {
            return $payment->routingDecisions
                ->sortByDesc(function (BillingRoutingDecision $decision) {
                    return optional($decision->created_at)->getTimestamp() ?? 0;
                })
                ->first();
        }

        return $payment->routingDecisions()
            ->where('immutable_until_terminal_state', true)
            ->latest('id')
            ->first();
    }

    private function expectedProviderReference(Payment $payment, string $provider): string
    {
        return match ($provider) {
            'pesapal' => trim((string) (
                $payment->transaction_reference
                ?? data_get($payment->raw_payload, 'pesapal.order_tracking_id')
                ?? data_get($payment->payment_data, 'link_proxy.provider_reference')
                ?? ''
            )),
            default => trim((string) ($payment->reference_number ?: $payment->transaction_reference ?: '')),
        };
    }

    private function winningStatus(Payment $payment): string
    {
        if ($this->isCompleted($payment)) {
            return 'completed';
        }

        return strtolower(trim((string) ($payment->status ?? 'pending')));
    }

    private function isCompleted(Payment $payment): bool
    {
        return (string) $payment->status === 'completed'
            || strtolower(trim((string) data_get($payment->payment_data, 'canonical_state.payment_intent_status', ''))) === 'completed'
            || (bool) $payment->wallet_transaction_id;
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['completed', 'failed'], true);
    }
}
