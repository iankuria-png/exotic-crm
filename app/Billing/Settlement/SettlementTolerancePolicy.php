<?php

namespace App\Billing\Settlement;

use App\Models\BillingRoutingDecision;
use App\Models\Payment;
use App\Services\CurrencyCanonicalizer;

class SettlementTolerancePolicy
{
    public function __construct(
        private readonly CurrencyCanonicalizer $currencyCanonicalizer
    ) {
    }

    public function evaluate(Payment $payment, array $providerPayload = [], array $options = []): array
    {
        $decision = $this->latestPinnedDecision($payment);
        $providerType = strtolower(trim((string) (
            $options['provider_type_key'] ?? null
            ?? $decision?->provider_type_key
            ?? $payment->provider_key
            ?? 'unknown'
        )));

        $chargePricing = is_array(data_get($payment->payment_data, 'charge_pricing'))
            ? data_get($payment->payment_data, 'charge_pricing')
            : [];
        $quotedPricing = is_array(data_get($payment->payment_data, 'quoted_pricing'))
            ? data_get($payment->payment_data, 'quoted_pricing')
            : [];

        $expectedAmount = $this->normalizeMoney(
            $options['expected_amount'] ?? ($chargePricing['amount'] ?? $payment->amount)
        );
        $expectedCurrency = $this->normalizeCurrency(
            $options['expected_currency'] ?? ($chargePricing['currency'] ?? $payment->currency)
        );
        $quotedAmount = $this->normalizeMoney($quotedPricing['amount'] ?? $expectedAmount);
        $quotedCurrency = $this->normalizeCurrency($quotedPricing['currency'] ?? $expectedCurrency);

        [$settledAmount, $settledCurrency] = $this->resolveSettledAmountAndCurrency($providerType, $providerPayload, $options);
        [$feeAmount, $feeCurrency] = $this->resolveFeeAmountAndCurrency($providerType, $providerPayload, $options, $settledCurrency);
        [$fxRate, $fxSource, $fxLockedAt] = $this->resolveFxMetadata($payment, $decision, $options);
        $currencyContext = $this->currencyContext($payment, $providerPayload, $options);
        $expectedSettlementCurrency = $this->canonicalSettlementCurrency($expectedCurrency, $currencyContext);
        $settledSettlementCurrency = $this->canonicalSettlementCurrency($settledCurrency, $currencyContext);
        $quotedSettlementCurrency = $this->canonicalSettlementCurrency($quotedCurrency, $currencyContext);
        $feeSettlementCurrency = $this->canonicalSettlementCurrency($feeCurrency, $currencyContext);

        $varianceAmount = null;
        $reviewRequired = false;
        $disposition = 'amount_unavailable';
        $settlementStatus = 'amount_unavailable';
        $completionPolicy = 'allow_completion';

        if ($settledAmount !== null && $settledCurrency !== null) {
            if ($settledSettlementCurrency !== $expectedSettlementCurrency) {
                $disposition = 'currency_mismatch_review_required';
                $settlementStatus = 'currency_mismatch';
                $completionPolicy = 'hold_for_review';
                $reviewRequired = true;
            } else {
                $varianceAmount = round($settledAmount - $expectedAmount, 2);
                $toleranceAmount = $this->normalizeMoney($options['tolerance_amount'] ?? 0.01) ?? 0.01;

                if (abs($varianceAmount) <= $toleranceAmount) {
                    $disposition = 'accepted_exact';
                    $settlementStatus = 'settled_exact';
                } elseif ($varianceAmount < 0) {
                    $disposition = 'underpaid_review_required';
                    $settlementStatus = 'underpaid';
                    $completionPolicy = 'hold_for_review';
                    $reviewRequired = true;
                } else {
                    $disposition = 'overpaid_review_required';
                    $settlementStatus = 'overpaid';
                    $reviewRequired = true;
                }
            }
        }

        return [
            'provider_type_key' => $providerType !== '' ? $providerType : 'unknown',
            'expected_amount' => $expectedAmount,
            'expected_currency' => $expectedCurrency,
            'expected_settlement_currency' => $expectedSettlementCurrency,
            'quoted_amount' => $quotedAmount,
            'quoted_currency' => $quotedCurrency,
            'quoted_settlement_currency' => $quotedSettlementCurrency,
            'settled_amount' => $settledAmount,
            'settled_currency' => $settledCurrency,
            'settled_settlement_currency' => $settledSettlementCurrency,
            'fee_amount' => $feeAmount,
            'fee_currency' => $feeCurrency,
            'fee_settlement_currency' => $feeSettlementCurrency,
            'fx_rate' => $fxRate,
            'fx_source' => $fxSource,
            'fx_locked_at' => $fxLockedAt,
            'variance_amount' => $varianceAmount,
            'disposition' => $disposition,
            'settlement_status' => $settlementStatus,
            'completion_policy' => $completionPolicy,
            'review_required' => $reviewRequired,
        ];
    }

    private function currencyContext(Payment $payment, array $providerPayload, array $options): array
    {
        $platform = $payment->relationLoaded('platform')
            ? $payment->platform
            : $payment->platform()->first();

        return [
            'platform_country' => $options['platform_country'] ?? $platform?->country,
            'country' => $options['country'] ?? data_get($providerPayload, 'country'),
            'platform_name' => $options['platform_name'] ?? $platform?->name,
            'name' => $options['name'] ?? $platform?->name,
        ];
    }

    private function canonicalSettlementCurrency(?string $currency, array $context): ?string
    {
        if ($currency === null || $currency === '') {
            return null;
        }

        $resolved = $this->currencyCanonicalizer->resolve($currency, $context);

        return $resolved['code'] ?? $currency;
    }

    private function latestPinnedDecision(Payment $payment): ?BillingRoutingDecision
    {
        if ($payment->relationLoaded('routingDecisions')) {
            return $payment->routingDecisions
                ->sortByDesc(static fn (BillingRoutingDecision $decision) => optional($decision->created_at)->getTimestamp() ?? 0)
                ->first();
        }

        return $payment->routingDecisions()
            ->where('immutable_until_terminal_state', true)
            ->latest('id')
            ->first();
    }

    private function resolveSettledAmountAndCurrency(string $providerType, array $providerPayload, array $options): array
    {
        $settledAmount = $this->normalizeMoney(
            $options['settled_amount'] ?? $this->providerSettledAmount($providerType, $providerPayload)
        );
        $settledCurrency = $this->normalizeCurrency(
            $options['settled_currency'] ?? $this->providerSettledCurrency($providerPayload)
        );

        return [$settledAmount, $settledCurrency];
    }

    private function resolveFeeAmountAndCurrency(string $providerType, array $providerPayload, array $options, ?string $settledCurrency): array
    {
        $feeAmount = $this->normalizeMoney(
            $options['fee_amount'] ?? $this->providerFeeAmount($providerType, $providerPayload)
        );
        $feeCurrency = $this->normalizeCurrency(
            $options['fee_currency'] ?? $settledCurrency ?? $this->providerSettledCurrency($providerPayload)
        );

        return [$feeAmount, $feeCurrency];
    }

    private function resolveFxMetadata(Payment $payment, ?BillingRoutingDecision $decision, array $options): array
    {
        $fxOverride = is_array(data_get($decision?->snapshot_json, 'fx_quote.fx_override'))
            ? data_get($decision?->snapshot_json, 'fx_quote.fx_override')
            : [];
        $fxApplied = (bool) data_get($fxOverride, 'applied', false);

        $fxRate = $this->normalizeMoney($options['fx_rate'] ?? data_get($fxOverride, 'rate'));
        $fxSource = $options['fx_source']
            ?? ($fxApplied ? 'self_checkout_override' : null);
        $fxLockedAt = $options['fx_locked_at']
            ?? optional($decision?->created_at)->toIso8601String()
            ?? null;

        return [$fxRate, $fxSource, $fxLockedAt];
    }

    private function providerSettledAmount(string $providerType, array $providerPayload): mixed
    {
        $rawAmount = data_get($providerPayload, 'amount')
            ?? data_get($providerPayload, 'paid_amount')
            ?? data_get($providerPayload, 'settlement_amount');

        if (!is_numeric($rawAmount)) {
            return null;
        }

        $amount = (float) $rawAmount;

        if ($providerType === 'paystack') {
            return round($amount / 100, 2);
        }

        return round($amount, 2);
    }

    private function providerFeeAmount(string $providerType, array $providerPayload): mixed
    {
        $rawFee = data_get($providerPayload, 'fees')
            ?? data_get($providerPayload, 'fee')
            ?? data_get($providerPayload, 'fee_amount');

        if (!is_numeric($rawFee)) {
            return null;
        }

        $amount = (float) $rawFee;

        if ($providerType === 'paystack') {
            return round($amount / 100, 2);
        }

        return round($amount, 2);
    }

    private function providerSettledCurrency(array $providerPayload): ?string
    {
        return $this->normalizeCurrency(
            data_get($providerPayload, 'currency')
            ?? data_get($providerPayload, 'settlement_currency')
            ?? data_get($providerPayload, 'payment_currency')
        );
    }

    private function normalizeMoney(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function normalizeCurrency(mixed $value): ?string
    {
        $currency = strtoupper(trim((string) $value));

        return $currency !== '' ? $currency : null;
    }
}
