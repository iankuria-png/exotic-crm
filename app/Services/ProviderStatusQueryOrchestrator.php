<?php

namespace App\Services;

use App\Models\BillingRoutingDecision;
use App\Models\Payment;
use InvalidArgumentException;
use RuntimeException;

class ProviderStatusQueryOrchestrator
{
    public function __construct(
        private readonly BillingModeService $billingModeService,
        private readonly HostedCheckoutService $hostedCheckoutService
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
            'pesapal' => $this->hostedCheckoutService->verifyPesapalTransaction(
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
}
