<?php

namespace App\Services\Mcp\Diagnostics;

use App\Models\BillingWebhookEvent;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Mcp\McpAuthorizationContext;
use App\Services\Mcp\McpProtocolException;
use App\Services\Mcp\OpaqueEntityLocator;

class PaymentFlowTraceService
{
    public function trace(string $locator, McpAuthorizationContext $auth): array
    {
        $payment = Payment::query()->latest('id')->get()->first(fn (Payment $item) => app(OpaqueEntityLocator::class)->resolvesPayment($locator, (int) $item->id));
        if (! $payment || ($auth->platformIds !== null && ! in_array((int) $payment->platform_id, $auth->platformIds, true))) {
            throw McpProtocolException::rpc(-32001, 'Payment observation not found.', 'payment_not_found', 404);
        }
        $attempts = PaymentAttempt::query()->where('payment_id', $payment->id)->get(['status', 'error_code', 'created_at']);
        $webhooks = BillingWebhookEvent::query()->where('payment_id', $payment->id)->get(['signature_status', 'processing_status', 'received_at', 'processed_at']);

        return ['locator' => $locator, 'environment' => $payment->provider_environment ?: 'unknown', 'stages' => [
            ['stage' => 'initiation', 'state' => $payment->status],
            ['stage' => 'provider', 'state' => $attempts->last()?->status ?: 'unobserved', 'code' => $attempts->last()?->error_code],
            ['stage' => 'callback', 'state' => $webhooks->last()?->processing_status ?: 'unobserved', 'signature' => $webhooks->last()?->signature_status],
            ['stage' => 'activation', 'state' => $payment->deal_id ? 'deal_linked' : 'not_observed'],
        ], 'caveats' => ['This trace is read-only; WordPress cache visibility can be delayed.']];
    }
}
