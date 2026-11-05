<?php

namespace App\Services\SubscriptionAudit;

/** Financial source records are input only; the result is a deliberate MCP-safe projection. */
class SubscriptionAuditClassifier
{
    public function project(array $event, ?object $deal, ?object $payment, array $platform, array $aliases, array &$allocated): array
    {
        $terms = $event['terms'];
        $reasons = $event['reasons'] ?? [];
        $isObservation = $event['event_type'] === 'observation';
        $isExtension = $event['event_type'] === 'extension';
        $method = strtolower((string) ($terms['payment_method'] ?? ''));
        $free = (bool) ($terms['is_free_trial'] ?? ($isObservation ? ($deal->is_free_trial ?? false) : false)) || $method === 'free_trial';
        $approved = (int) ($terms['free_trial_approved_by'] ?? $terms['discount_approved_by'] ?? 0);
        $currentApprover = (int) ($free ? ($deal->free_trial_approved_by ?? 0) : ($deal->discount_approved_by ?? 0));
        $discount = $terms['discount_percentage'] ?? ($isObservation ? ($deal->discount_percentage ?? null) : null);
        $discountMinor = AuditValue::minor($discount);
        $authorisation = $free || ($discountMinor ?? 0) > 0
            ? ($approved ? 'approval_recorded' : 'approval_not_recorded')
            : 'not_required_by_recorded_terms';
        if (! $approved && ! empty($event['approval_candidates'])) {
            $authorisation = 'approval_evidence_available_link_unverified';
            $reasons[] = 'approval_effect_link_unverified';
        }
        if ($authorisation === 'approval_not_recorded') {
            $reasons[] = 'approval_not_recorded';
        }
        if (! $event['actor_id'] && ! $event['automatic']) {
            $reasons[] = 'actor_not_recorded';
        }
        if ($isObservation) {
            $reasons[] = 'historical_event_not_recorded';
        }

        // A retained package price is not evidence of a particular extension's charge.
        $expected = AuditValue::minor($terms['amount'] ?? ($isObservation ? ($deal->amount ?? null) : null));
        $valuation = $isObservation ? 'current_deal_observation' : 'action_terms';
        if ($isObservation) {
            $expected = null;
            $valuation = 'historical_terms_not_recorded';
        }
        if ($isExtension && ! $event['payment_id']) {
            $expected = null;
            $valuation = 'unpriced_extension';
        }
        if ($free) {
            $expected = $approved ? 0 : null;
            $valuation = $approved ? 'approved_free_record' : 'free_access_approval_unverified';
        }
        if ($expected !== null && $expected < 0) {
            $expected = null;
        }
        $currency = strtoupper(trim((string) ($terms['currency'] ?? $deal->currency ?? $platform['currency'] ?? '')));
        if (! preg_match('/^[A-Z]{3}$/D', $currency)) {
            $currency = 'UNKNOWN';
        }
        $test = strtolower((string) ($payment->record_classification ?? '')) === 'test'
            || strtolower(trim((string) ($payment->provider_environment ?? ''))) === 'sandbox'
            || in_array($payment->audit_test_mode ?? null, [true, 1, '1', 'true'], true)
            || in_array($terms['test_mode'] ?? null, [true, 1, '1', 'true'], true);
        // A sandbox receipt cannot hide a real entitlement from the live exception set.
        $population = in_array($terms['test_mode'] ?? null, [true, 1, '1', 'true'], true) || ($terms['record_classification'] ?? null) === 'test' ? 'test' : 'live';
        $state = 'no_match';
        $covered = 0;
        $rawStatus = strtolower(trim((string) ($payment->status ?? '')));
        $status = in_array($rawStatus, ['completed', 'expired', 'pending', 'failed', 'cancelled', 'canceled', 'refunded', 'reversed', 'processing', 'awaiting_payment'], true) ? $rawStatus : ($payment ? 'other' : null);
        $matchBasis = $event['match_basis'] ?? 'action_payment_identity';
        if (! $payment) {
            $reasons[] = $event['payment_id'] ? 'linked_payment_missing' : 'payment_not_linked';
        } elseif ($test) {
            $state = 'excluded_test_payment';
            $reasons[] = 'test_or_sandbox_evidence';
        } elseif (($payment->purpose ?? null) !== null && ($payment->purpose ?? null) !== 'subscription') {
            $state = 'purpose_conflict';
            $reasons[] = 'non_subscription_payment';
        } elseif ((int) $payment->platform_id !== (int) $event['platform_id']
            || ($payment->client_id && $event['client_id'] && (int) $payment->client_id !== (int) $event['client_id'])
            || ($payment->deal_id && $event['deal_id'] && (int) $payment->deal_id !== (int) $event['deal_id'])) {
            $state = 'identity_conflict';
            $reasons[] = 'payment_identity_conflict';
        } elseif (! $event['client_id'] || (! $payment->client_id && (int) ($payment->deal_id ?? 0) !== (int) $event['deal_id'])) {
            $state = 'identity_unverified';
            $reasons[] = 'payment_client_identity_unverified';
        } elseif (strtoupper((string) $payment->currency) !== $currency || $currency === 'UNKNOWN') {
            $state = 'currency_conflict';
            $reasons[] = 'payment_currency_conflict';
        } elseif (in_array($matchBasis, ['reverse_link_candidate', 'current_deal_payment_context_only'], true)) {
            $state = 'candidate_only';
            $reasons[] = 'event_specific_payment_unverified';
        } elseif (($payment->manual_payment_bundle_id ?? null) || ($payment->wallet_transaction_id ?? null)) {
            $state = 'allocation_unverified';
            $reasons[] = 'bundle_or_wallet_allocation_unverified';
        } elseif (! in_array($rawStatus, ['completed', 'expired'], true)
            || in_array($payment->resolution_code ?? null, ['reversed', 'invalid_reference'], true)
            || ($payment->reconciliation_state ?? null) === 'manual_review') {
            $state = 'payment_not_successful';
            $reasons[] = in_array($rawStatus, ['failed', 'cancelled', 'canceled', 'refunded', 'reversed'], true) ? 'payment_'.$status : 'payment_requires_review';
        } else {
            $available = max(0, AuditValue::minor($payment->amount) ?? 0);
            $prior = $allocated[(int) $payment->id] ?? 0;
            $remaining = max(0, $available - $prior);
            $covered = $expected === null ? 0 : min($expected, $remaining);
            if ($expected === null) {
                $state = 'amount_unverified';
                $reasons[] = 'event_charge_unpriced';
            } elseif ($remaining < $expected) {
                $state = $prior > 0 ? 'allocation_conflict' : 'amount_mismatch';
                $reasons[] = $prior > 0 ? 'payment_value_already_allocated' : 'payment_amount_shortfall';
            } else {
                $state = 'crm_recorded_only';
            }
            $allocated[(int) $payment->id] = $prior + $covered;
        }
        if ($free && $approved) {
            $state = 'authorised_free_record';
            $covered = 0;
            $reasons = array_values(array_diff($reasons, ['payment_not_linked']));
        }
        if ($expected === null || $currency === 'UNKNOWN') {
            $reasons[] = 'exposure_unpriced';
        }
        $exposure = $population === 'test' ? null : ($expected === null || $currency === 'UNKNOWN' ? null : max(0, $expected - $covered));
        $actor = $this->staff($event['actor_id'], $aliases);
        $eventRef = AuditValue::handle('evt', $event['source_type'].':'.$event['source_id']);
        $payload = [
            'event_ref' => $eventRef,
            'event_type' => $event['event_type'],
            'occurred_at' => AuditValue::iso($event['occurred_at']),
            'recorded_at' => AuditValue::iso($event['recorded_at']),
            'platform_id' => (int) $event['platform_id'],
            'website' => $platform['website'] ?? null,
            'market_label' => $platform['market_label'] ?? 'Unknown website',
            'country' => $platform['country'] ?? null,
            'population' => $population,
            'client_handle' => AuditValue::handle('cli', $event['client_id']),
            'deal_handle' => AuditValue::handle('deal', $event['deal_id']),
            'payment_locator' => AuditValue::handle('pay', $event['payment_id']),
            'actor' => $actor,
            'execution' => $event['automatic'] ? 'automatic_recorded' : ($event['actor_id'] ? 'staff_recorded' : 'unknown'),
            'approver' => $this->staff($approved ?: null, $aliases),
            'current_approval_field' => $currentApprover ? ['staff' => $this->staff($currentApprover, $aliases), 'basis' => 'current_state_not_historical_approval'] : null,
            'payment_confirmer' => $this->staff($payment->confirmed_by ?? null, $aliases),
            'assigned_owner' => $this->staff($deal->assigned_to ?? null, $aliases),
            'old_expiry' => AuditValue::iso($event['old_expiry']),
            'old_expiry_evidence' => $event['old_expiry_known'] ? ($event['old_expiry'] === null ? 'recorded_no_expiry' : 'recorded') : 'not_recorded',
            'new_expiry' => AuditValue::iso($event['new_expiry']),
            'new_expiry_evidence' => $event['new_expiry_known'] ? ($event['new_expiry'] === null ? 'recorded_no_expiry' : 'recorded') : 'not_recorded',
            'effect_state' => $isObservation ? 'current_deal_observation' : 'successful_action_recorded',
            'historical_completeness' => $isObservation ? 'partial' : $event['completeness'],
            'payment_state' => $state,
            'payment_status_now' => $status,
            'payment_status_at_action' => 'not_independently_reconstructed',
            'payment_match_basis' => $matchBasis,
            'provider_assurance' => 'not_independently_verified',
            'authorisation_state' => $authorisation,
            'approval_evidence_candidates' => $event['approval_candidates'] ?? [],
            'free_trial' => $free,
            'discount_percentage' => $discountMinor !== null ? AuditValue::decimal($discountMinor) : null,
            'amount' => AuditValue::decimal($expected),
            'observed_current_deal_amount' => $isObservation ? AuditValue::decimal(AuditValue::minor($deal->amount ?? null)) : null,
            'currency' => $currency,
            'covered_amount' => AuditValue::decimal($covered),
            'potential_exposure' => AuditValue::decimal($exposure),
            'valuation_basis' => $valuation,
            'exception_codes' => array_values(array_unique($reasons)),
            'evidence' => ['source_kind' => $event['source_type'], 'source_ref' => AuditValue::handle('src', $event['source_type'].':'.$event['source_id']), 'supporting_source_refs' => $event['supporting_source_refs'] ?? []],
        ];

        return [
            'event_ref' => $eventRef, 'platform_id' => $event['platform_id'], 'country' => $platform['country'] ?? null,
            'occurred_at' => $event['occurred_at'], 'event_type' => $event['event_type'], 'staff_handle' => $actor['staff_handle'] ?? null,
            'exception' => count($payload['exception_codes']) > 0, 'population' => $population,
            'source_type' => $event['source_type'], 'source_id' => $event['source_id'],
            'client_id' => $event['client_id'], 'deal_id' => $event['deal_id'], 'payment_id' => $event['payment_id'],
            'actor_id' => $event['actor_id'], 'approver_id' => $approved ?: null, 'payload' => $payload,
        ];
    }

    private function staff(?int $id, array $aliases): ?array
    {
        if (! $id) {
            return null;
        }

        return ['staff_handle' => AuditValue::handle('staff', $id), 'agent_alias' => $aliases[$id] ?? null];
    }
}
