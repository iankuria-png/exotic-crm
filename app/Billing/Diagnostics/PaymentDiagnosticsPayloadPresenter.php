<?php

namespace App\Billing\Diagnostics;

use App\Billing\BillingPermissions;
use App\Models\User;

final class PaymentDiagnosticsPayloadPresenter
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function present(array $payload, User $viewer): array
    {
        $canViewRaw = BillingPermissions::canViewRawPaymentDiagnostics($viewer);

        $presented = $payload;
        $presented['permissions'] = [
            'view_raw_payloads' => $canViewRaw,
        ];

        if (!$canViewRaw) {
            $presented['browser_meta'] = $this->presentBrowserMeta($presented['browser_meta'] ?? null);
            $presented['attempts'] = $this->presentAttempts($presented['attempts'] ?? []);
            $presented['audit_trail'] = $this->presentAuditTrail($presented['audit_trail'] ?? []);
            $presented['provider_transactions'] = $this->presentProviderTransactions($presented['provider_transactions'] ?? []);
        }

        $presented['structured_diagnostics'] = $this->presentStructuredDiagnostics(
            $presented['structured_diagnostics'] ?? null,
            $viewer,
            $canViewRaw
        );

        return $presented;
    }

    /**
     * @param  mixed  $value
     * @return array<string, mixed>|null
     */
    private function presentStructuredDiagnostics(mixed $value, User $viewer, bool $canViewRaw): ?array
    {
        if ($value instanceof PaymentDiagnosticsView) {
            $value = [
                'payment_id' => $value->paymentId,
                'source' => $value->source,
                'sections' => $value->sections,
                'meta' => $value->meta,
            ];
        }

        if (!is_array($value)) {
            return null;
        }

        $meta = is_array($value['meta'] ?? null) ? $value['meta'] : [];
        $meta['viewer_role'] = $viewer->role;
        $meta['redaction_profile'] = $canViewRaw ? 'manager' : 'operator';

        $value['meta'] = $meta;

        return $value;
    }

    /**
     * @param  mixed  $value
     * @return array<string, mixed>|null
     */
    private function presentBrowserMeta(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        return [
            'context_type' => $value['context_type'] ?? null,
            'user_agent_family' => $value['user_agent_family'] ?? null,
            'device_type' => $value['device_type'] ?? null,
            'origin_url' => $value['origin_url'] ?? null,
            'referrer' => $value['referrer'] ?? null,
            'ip_hash' => null,
            'request_id' => null,
            'redacted' => true,
        ];
    }

    /**
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    private function presentAttempts(mixed $value): array
    {
        $items = $this->normalizeList($value);

        if ($items === null) {
            return [];
        }

        return array_map(function ($attempt) {
            if (!is_array($attempt)) {
                return [];
            }

            $attempt['request_meta'] = [
                'provider_environment' => data_get($attempt, 'request_meta.provider_environment'),
                'context_type' => data_get($attempt, 'request_meta.context_type'),
                'request_id' => null,
                'redacted' => true,
            ];
            $attempt['response_meta'] = [
                'provider_status' => data_get($attempt, 'response_meta.provider_status'),
                'provider_message' => data_get($attempt, 'response_meta.provider_message'),
                'provider_payload' => '[redacted]',
                'redacted' => true,
            ];

            return $attempt;
        }, $items);
    }

    /**
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    private function presentAuditTrail(mixed $value): array
    {
        $items = $this->normalizeList($value);

        if ($items === null) {
            return [];
        }

        return array_map(function ($entry) {
            if (!is_array($entry)) {
                return [];
            }

            $entry['before_state'] = ['redacted' => true];
            $entry['after_state'] = ['redacted' => true];

            return $entry;
        }, $items);
    }

    /**
     * @param  mixed  $value
     * @return array<int, array<string, mixed>>
     */
    private function presentProviderTransactions(mixed $value): array
    {
        $items = $this->normalizeList($value);

        if ($items === null) {
            return [];
        }

        return array_map(function ($transaction) {
            if (!is_array($transaction)) {
                return [];
            }

            return $transaction;
        }, $items);
    }

    /**
     * @param  mixed  $value
     * @return array<int, mixed>|null
     */
    private function normalizeList(mixed $value): ?array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (is_iterable($value)) {
            return array_values(iterator_to_array($value));
        }

        return null;
    }
}
