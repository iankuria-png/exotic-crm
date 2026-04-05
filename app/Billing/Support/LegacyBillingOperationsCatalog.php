<?php

namespace App\Billing\Support;

class LegacyBillingOperationsCatalog
{
    /**
     * Catalog of manual operational billing flows that still matter in production.
     *
     * Each entry is explicitly preserved, migrated, or retired so the Payments
     * workspace is not carrying "legacy by accident" behavior.
     *
     * @return array<int, array<string, mixed>>
     */
    public function paymentsWorkspaceCatalog(): array
    {
        return [
            [
                'key' => 'auto_match',
                'label' => 'Auto-match payment',
                'surface' => 'payments_workspace',
                'disposition' => 'preserved',
                'handler' => 'payment_matching_service',
                'replacement' => null,
                'summary' => 'Keep queue auto-match as an operator accelerator while matching remains a human-assisted workflow.',
            ],
            [
                'key' => 'manual_match',
                'label' => 'Manual match payment',
                'surface' => 'payments_workspace',
                'disposition' => 'preserved',
                'handler' => 'payment_matching_service',
                'replacement' => null,
                'summary' => 'Keep manual client confirmation for low-confidence matches and imported payments.',
            ],
            [
                'key' => 'retry_stk',
                'label' => 'Retry STK push',
                'surface' => 'payments_workspace',
                'disposition' => 'preserved',
                'handler' => 'legacy_stk_compatibility',
                'replacement' => 'provider adapter retry path',
                'summary' => 'Preserve STK retry behind the compatibility bridge until Daraja/KopoKopo adapter retries are live.',
            ],
            [
                'key' => 'send_link',
                'label' => 'Send payment link',
                'surface' => 'payments_workspace',
                'disposition' => 'migrated',
                'handler' => 'payment_link_service',
                'replacement' => 'routing and proxy lifecycle bridge',
                'summary' => 'Payment-link recovery stays available, but now runs through the routing engine and proxy lifecycle bridge.',
            ],
            [
                'key' => 'create_subscription',
                'label' => 'Create subscription',
                'surface' => 'payments_workspace',
                'disposition' => 'preserved',
                'handler' => 'subscription_provisioning_service',
                'replacement' => null,
                'summary' => 'Keep matched-payment subscription creation as a controlled back-office recovery action.',
            ],
            [
                'key' => 'manual_close',
                'label' => 'Manual close payment',
                'surface' => 'payments_workspace',
                'disposition' => 'preserved',
                'handler' => 'payment_queue_manual_close',
                'replacement' => null,
                'summary' => 'Keep explicit manual closure for timeout, cancellation, fraud, and duplicate request resolution.',
            ],
            [
                'key' => 'review_state',
                'label' => 'Manual review state',
                'surface' => 'payments_workspace',
                'disposition' => 'preserved',
                'handler' => 'payment_queue_review_state',
                'replacement' => null,
                'summary' => 'Keep operator review-state transitions until diagnostics and settlement tooling fully automate triage.',
            ],
            [
                'key' => 'batch_match',
                'label' => 'Batch auto-match queue',
                'surface' => 'payments_workspace',
                'disposition' => 'preserved',
                'handler' => 'payment_matching_service',
                'replacement' => null,
                'summary' => 'Keep batch queue matching for import-heavy markets where payments arrive before structured checkout data.',
            ],
            [
                'key' => 'direct_operator_provider_override',
                'label' => 'Direct operator provider override',
                'surface' => 'deal_and_client_payment_link_flows',
                'disposition' => 'retired',
                'handler' => null,
                'replacement' => 'market billing policy plus audited admin override',
                'summary' => 'Normal operators no longer choose payment-link providers directly. Billing policy is the default; admins can override with audit.',
            ],
            [
                'key' => 'direct_proxy_token_handling',
                'label' => 'Direct proxy token handling',
                'surface' => 'proxy_checkout',
                'disposition' => 'retired',
                'handler' => null,
                'replacement' => 'billing proxy lifecycle service and diagnostics',
                'summary' => 'Proxy lifecycle is now durable and observable through billing proxy sessions instead of ad hoc token state only in payment_data.',
            ],
        ];
    }

    public function workspaceSummary(): array
    {
        $catalog = $this->paymentsWorkspaceCatalog();

        return [
            'preserved' => count(array_filter($catalog, fn (array $entry) => $entry['disposition'] === 'preserved')),
            'migrated' => count(array_filter($catalog, fn (array $entry) => $entry['disposition'] === 'migrated')),
            'retired' => count(array_filter($catalog, fn (array $entry) => $entry['disposition'] === 'retired')),
        ];
    }
}
