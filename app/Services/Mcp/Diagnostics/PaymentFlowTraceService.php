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
        // Locators are one-way HMACs: scan only IDs in bounded pages, never receipt
        // payloads or the full collection of Eloquent payment models.
        $ids = Payment::query()->select('id');
        if ($auth->platformIds !== null) {
            $ids->whereIn('platform_id', $auth->platformIds);
        }
        $locators = app(OpaqueEntityLocator::class);
        $match = $ids->toBase()->lazyByIdDesc(500)->first(fn (object $item) => $locators->resolvesPayment($locator, (int) $item->id));
        $payment = $match ? Payment::query()->find($match->id, ['id', 'platform_id', 'status', 'provider_environment', 'deal_id']) : null;
        if (! $payment || ($auth->platformIds !== null && ! in_array((int) $payment->platform_id, $auth->platformIds, true))) {
            throw McpProtocolException::rpc(-32001, 'Payment observation not found.', 'payment_not_found', 404);
        }
        $attempt = PaymentAttempt::query()->where('payment_id', $payment->id)->orderByDesc('id')->first(['status', 'error_code']);
        $webhook = BillingWebhookEvent::query()->where('payment_id', $payment->id)->orderByDesc('id')->first(['signature_status', 'processing_status']);

        return ['locator' => $locator, 'environment' => $payment->provider_environment ?: 'unknown', 'stages' => [
            ['stage' => 'initiation', 'state' => $payment->status],
            ['stage' => 'provider', 'state' => $attempt?->status ?: 'unobserved', 'code' => $attempt?->error_code],
            ['stage' => 'callback', 'state' => $webhook?->processing_status ?: 'unobserved', 'signature' => $webhook?->signature_status],
            ['stage' => 'activation', 'state' => $payment->deal_id ? 'deal_linked' : 'not_observed'],
        ], 'caveats' => ['This trace is read-only; WordPress cache visibility can be delayed.']];
    }
}
