<?php

namespace App\Services\FlexibleAmounts;

use App\Models\Client;
use App\Models\Payment;
use App\Services\PaymentFailureReasonClassifier;

class FlexibleRescueService
{
    public function category(Payment $payment, array $signals = []): string
    {
        $codes = $signals['codes'] ?? [];
        $messages = $signals['messages'] ?? [];
        $messages[] = $payment->failure_reason;
        foreach (['failure_data.code', 'failure_data.error_code', 'failureReason.failureCode', 'provider_failure.code', 'pawapay.failureReason.failureCode'] as $path) {
            $codes[] = data_get($payment->raw_payload, $path);
        }
        foreach (['failure_data.message', 'failure_data.reason', 'failureReason.failureMessage', 'provider_failure.message', 'pawapay.failureReason.failureMessage'] as $path) {
            $messages[] = data_get($payment->raw_payload, $path);
        }
        foreach (['failure_code', 'failure.error_code', 'failureReason.failureCode'] as $path) {
            $codes[] = data_get($payment->payment_data, $path);
        }
        foreach (['failure_reason', 'failure.message', 'failureReason.failureMessage'] as $path) {
            $messages[] = data_get($payment->payment_data, $path);
        }
        foreach ($payment->providerTransactions()->orderByDesc('id')->limit(5)->get(['provider_failure_code', 'provider_failure_message']) as $tx) {
            $codes[] = $tx->provider_failure_code;
            $messages[] = $tx->provider_failure_message;
        }

        return app(PaymentFailureReasonClassifier::class)->classify(['codes' => array_filter($codes), 'messages' => array_filter($messages)])['code'];
    }

    public function forPayment(Payment $payment, string $surface, array $signals = []): array
    {
        if (! in_array($payment->status, ['failed', 'cancelled', 'canceled'], true) || $this->category($payment, $signals) !== 'insufficient_funds' || ! $payment->client) {
            return [];
        }
        $rules = app(FlexibleRulesService::class)->rules($payment->platform);
        if (! $rules['rescue_enabled'] || $rules['mode'] === 'off' || ! ($rules['surfaces'][$surface] ?? false)) {
            return [];
        }
        $out = [];
        try {
            $state = app(FlexibleQuoteService::class)->state($payment->client);
            foreach ([3, 7] as $days) {
                if ($days < $rules['min_days'] || $days > $rules['max_days']) {
                    continue;
                }
                $quote = app(FlexibleQuoteService::class)->quote($payment->platform, $payment->client, ['product_id' => $payment->product_id, 'amount' => (string) $payment->amount, 'requested_days' => $days], $surface, $state);
                if (FlexiblePricingEngine::minor($quote['charge']) < FlexiblePricingEngine::minor((string) $payment->amount)) {
                    $out[] = $quote + ['failed_payment_id' => $payment->id];
                }
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return [];
        }

        return $out;
    }

    public function validateDashboard(Client $client, array $input, array $quote): void
    {
        if (empty($input['rescue_payment_id'])) {
            return;
        }
        $payment = Payment::where('platform_id', $client->platform_id)->where('client_id', $client->id)->findOrFail($input['rescue_payment_id']);
        $offers = $this->forPayment($payment, 'dashboard');
        abort_unless($payment->product_id === $quote['product_id'] && collect($offers)->contains(fn ($q) => $q['days'] === $quote['days'] && $q['charge'] === $quote['charge']), 422, 'Refresh the smaller payment options before trying again.');
    }
}
