<?php

namespace App\Services\Rebates;

use App\Models\Payment;

class RebateChannelResolver
{
    public function resolve(Payment $payment): ?string
    {
        if ((string) $payment->status !== 'completed' || (float) $payment->amount <= 0) {
            return null;
        }
        $d = $payment->payment_data ?? [];
        $source = $payment->source ?: data_get($payment->raw_payload, 'source');
        if ($payment->import_batch_id || $payment->manual_payment_bundle_id || in_array($source, ['deal_manual_payment', 'mpesa_import', 'mpesa_statement', 'manual_bundle', 'free_trial'], true) || data_get($d, 'is_free_trial') || $payment->deal?->is_free_trial) {
            return null;
        }
        if ($payment->purpose === 'wallet_topup') {
            return ($d['initiator'] ?? null) === 'companion' ? 'topup' : null;
        }
        if (! in_array($payment->purpose, ['subscription', null], true)) {
            return null;
        }
        if ($payment->source === 'manual_confirmation' && ($d['initiator'] ?? null) === 'companion' && data_get($d, 'manual_submission.submission_id')) {
            return 'manual_submission';
        }
        if (($d['initiator'] ?? null) === 'staff_link') {
            return 'staff_link';
        }
        if (($d['initiator'] ?? null) === 'sales' && in_array($payment->source, ['crm_activation', 'deal_payment_initiation', 'crm_lifecycle'], true)) {
            return 'sales_assisted';
        }
        if ($payment->source === 'wallet' && ($d['initiator'] ?? null) === 'companion') {
            return ($d['renewal_mode'] ?? null) === 'auto_renew' ? 'auto_renew' : 'wallet';
        }
        if (($d['initiator'] ?? null) === 'companion' && ($d['billing_surface'] ?? data_get($payment->raw_payload, 'billing_surface')) === 'self_service_subscription') {
            return 'self_checkout';
        }
        if (($d['initiator'] ?? null) === 'companion' && in_array($payment->source, ['manual_submission', 'payment_submission', 'manual_payment_submission'], true)) {
            return 'manual_submission';
        }

        return null;
    }

    // Historical volume is used only for cost projections. Grants never infer an initiator.
    public function forProjection(Payment $p): ?string
    {
        $channel = $this->resolve($p);
        if ($channel || data_get($p->payment_data, 'initiator')) {
            return $channel;
        }
        $source = $p->source ?: data_get($p->raw_payload, 'source');
        if ($p->status !== 'completed' || $p->import_batch_id || $p->manual_payment_bundle_id || $p->deal?->is_free_trial) {
            return null;
        }
        if ($p->purpose === 'wallet_topup' && $source === 'gateway') {
            return 'topup';
        }
        if (! in_array($p->purpose, ['subscription', null], true)) {
            return null;
        }

        return match ($source) {
            'wallet' => data_get($p->payment_data, 'renewal_mode') === 'auto_renew' ? 'auto_renew' : 'wallet',
            'self_checkout' => 'self_checkout',
            'crm_activation', 'deal_payment_initiation', 'crm_lifecycle' => 'sales_assisted',
            'manual_confirmation' => data_get($p->payment_data, 'manual_submission.submission_id') ? 'manual_submission' : null,
            default => null,
        };
    }

    public function sandbox(Payment $payment): bool
    {
        return $payment->isClassifiedTest() || (bool) data_get($payment->payment_data, 'test_mode') || $payment->provider_environment === 'sandbox'
            || ($payment->relationLoaded('routingDecisions') ? $payment->routingDecisions->sortByDesc('id')->first()?->environment : $payment->routingDecisions()->latest('id')->value('environment')) === 'sandbox';
    }
}
