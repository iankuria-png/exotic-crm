<?php

namespace App\Services\FlexibleAmounts;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Services\WpSyncFactory;

class FlexibleFulfilmentService
{
    public function apply(Payment $payment, Deal $deal, Client $client, int $days): array
    {
        try {
            return $this->applyOperation($payment, $deal, $client, $days);
        } catch (\Throwable $e) {
            throw new \App\Exceptions\FlexibleFulfilmentException('Payment received. The team is checking your activation.', 0, $e);
        }
    }

    private function applyOperation(Payment $payment, Deal $deal, Client $client, int $days): array
    {
        $snapshot = data_get($payment->payment_data, 'flexible_quote', []);
        abort_unless((int) ($snapshot['days'] ?? 0) === $days && ($snapshot['tier'] ?? '') === $deal->plan_type
            && (int) ($snapshot['product_id'] ?? 0) === (int) $deal->product_id && (int) $payment->client_id === (int) $client->id, 409, 'The paid quote and activation need a team check.');
        $key = (string) data_get($payment->payment_data, 'flexible_operation_key');
        abort_unless(\Illuminate\Support\Str::isUuid($key), 409, 'This payment needs a team check.');
        $sync = app(WpSyncFactory::class)->forPlatform($client->platform_id);
        // Recover before later holds, changed tier or catalogue. Native ledger binds every field.
        $saved = $sync->payLinkOperation($key);
        $result = $saved['operation'] ?? null;
        if (! $result) {
            $state = app(FlexibleQuoteService::class)->state($client);
            abort_if(($state['live'] ?? false) && $state['current_tier'] !== $deal->plan_type, 409, 'Payment received. Your plan changed; the team will check the activation.');
            $result = $sync->activatePayLink($client->wp_post_id, ['operation_key' => $key, 'crm_payment_id' => $payment->id, 'crm_deal_id' => $deal->id, 'wp_user_id' => $client->wp_user_id, 'product_type' => $deal->plan_type, 'duration_days' => $days]);
        }
        abort_unless(($result['state'] ?? '') === 'applied' && ($result['operation_key'] ?? '') === $key
            && (int) ($result['post_id'] ?? 0) === (int) $client->wp_post_id && (int) ($result['crm_payment_id'] ?? 0) === (int) $payment->id
            && (int) ($result['crm_deal_id'] ?? 0) === (int) $deal->id && ($result['product_type'] ?? '') === $deal->plan_type
            && (int) ($result['duration_days'] ?? 0) === $days && (int) ($result['escort_expire'] ?? 0) > 0, 409, 'Payment received. The saved activation needs a team check.');

        return $result;
    }
}
