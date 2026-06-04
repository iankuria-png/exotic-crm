<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Str;

class PaymentPresenter
{
    public function paymentMethod(Payment $payment): array
    {
        $payment->loadMissing('manualSubmission');

        if ($payment->manualSubmission) {
            return [
                'label' => 'Manual proof',
                'subtitle' => $payment->manualSubmission->manual_method_key
                    ? Str::of((string) $payment->manualSubmission->manual_method_key)->replace('_', ' ')->title()->toString()
                    : null,
                'source' => 'manual_submission',
            ];
        }

        $provider = trim((string) $payment->provider_key);
        if ($provider !== '') {
            return [
                'label' => Str::of($provider)->replace('_', ' ')->title()->replace('Mpesa', 'M-Pesa')->toString(),
                'subtitle' => null,
                'source' => 'provider_key',
            ];
        }

        if ((string) $payment->source === 'manual') {
            $subtitle = data_get($payment->payment_data, 'manual_submission_type')
                ?? data_get($payment->raw_payload, 'manual_submission_type');

            return [
                'label' => 'Manual',
                'subtitle' => $subtitle ? Str::of((string) $subtitle)->replace('_', ' ')->title()->toString() : null,
                'source' => 'manual',
            ];
        }

        $source = trim((string) $payment->source);

        return [
            'label' => $source !== '' ? Str::of($source)->replace('_', ' ')->title()->toString() : 'Unknown',
            'subtitle' => null,
            'source' => 'source',
        ];
    }

    public function paymentChannel(Payment $payment): array
    {
        $payment->loadMissing(['routingDecisions', 'providerTransactions', 'manualSubmission']);

        $provider = strtolower(trim((string) $payment->provider_key));
        $source = strtolower(trim((string) $payment->source));
        $origin = strtolower(trim((string) data_get($payment->payment_data, 'origin', '')));
        $billingSurface = strtolower(trim((string) data_get($payment->payment_data, 'billing_surface', '')));
        $matchConfidence = strtolower(trim((string) $payment->match_confidence));
        $reference = strtoupper(trim((string) ($payment->transaction_reference ?: $payment->reference_number)));

        if (
            $payment->manualSubmission
            || $payment->manual_payment_bundle_id
            || str_contains($provider, 'manual')
            || str_contains($source, 'manual')
            || str_contains($origin, 'manual')
            || str_contains($billingSurface, 'manual')
            || $matchConfidence === 'manual'
        ) {
            $label = $payment->manualSubmission ? 'Manual proof' : 'Agent manual';

            return [
                'key' => 'manual',
                'label' => $label,
                'description' => $payment->manualSubmission
                    ? 'Customer-submitted proof reviewed through the manual payment route.'
                    : 'Recorded by an agent after the customer shared an offline payment code.',
            ];
        }

        $routingDecision = $payment->routingDecisions
            ->sortByDesc(fn ($decision) => optional($decision->created_at)->getTimestamp() ?? 0)
            ->first();
        $hasStructuredProviderRoute = ($routingDecision && trim((string) $routingDecision->provider_type_key) !== '')
            || $payment->providerTransactions->isNotEmpty()
            || $provider !== '';

        if (!$hasStructuredProviderRoute && str_starts_with($reference, 'UE')) {
            return [
                'key' => 'manual',
                'label' => 'Agent manual',
                'description' => 'Legacy agent-entered payment reference without a provider route or transaction.',
            ];
        }

        if (
            $hasStructuredProviderRoute
            || in_array($source, ['hosted_checkout', 'payment_link', 'checkout', 'self_service', 'self_checkout'], true)
        ) {
            $providerName = $routingDecision?->provider_type_key ?: $payment->providerTransactions->first()?->provider_type_key ?: $payment->provider_key;

            return [
                'key' => 'self_service',
                'label' => 'Self-service',
                'description' => $providerName
                    ? sprintf('Activated through a provider route such as %s.', Str::headline((string) $providerName))
                    : 'Activated through payment links, hosted checkout, STK, or provider callbacks.',
            ];
        }

        return $this->paymentChannelFromValues($payment->provider_key, $payment->source);
    }

    public function paymentChannelFromKey(string $key): array
    {
        return match ($key) {
            'manual' => [
                'key' => 'manual',
                'label' => 'Manual',
                'description' => 'Agent-entered codes or customer-submitted proof.',
            ],
            'self_service' => [
                'key' => 'self_service',
                'label' => 'Self-service',
                'description' => 'Provider-routed collection such as PawaPay, checkout, STK, or callbacks.',
            ],
            default => [
                'key' => 'other',
                'label' => 'Other',
                'description' => 'Imported or legacy records without a clear collection channel.',
            ],
        };
    }

    private function paymentChannelFromValues($providerKey, $source): array
    {
        $provider = trim((string) $providerKey);
        $sourceValue = strtolower(trim((string) $source));

        if (str_contains(strtolower($provider), 'manual') || str_contains($sourceValue, 'manual')) {
            return [
                'key' => 'manual',
                'label' => 'Manual',
                'description' => 'Recorded by the team from an offline or direct confirmation.',
            ];
        }

        if ($provider !== '' || in_array($sourceValue, ['hosted_checkout', 'payment_link', 'checkout', 'self_service', 'self_checkout'], true)) {
            return [
                'key' => 'self_service',
                'label' => 'Self-service',
                'description' => 'Collected through payment links, hosted checkout, STK, or provider callbacks.',
            ];
        }

        return [
            'key' => 'other',
            'label' => 'Other',
            'description' => 'Imported or legacy records without a clear collection channel.',
        ];
    }
}
