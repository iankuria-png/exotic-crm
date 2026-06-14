<?php

namespace App\Services;

use Illuminate\Support\Str;

class PaymentFailureReasonClassifier
{
    public const UNCLASSIFIED = 'unclassified';

    private const LABELS = [
        'authorization_timeout' => 'Authorization timed out',
        'customer_declined' => 'Customer declined',
        'insufficient_funds' => 'Insufficient funds',
        'invalid_phone_account' => 'Invalid phone or account',
        'provider_network_unavailable' => 'Provider or network unavailable',
        'limits_compliance' => 'Limits or compliance restriction',
        'configuration_routing' => 'Configuration or routing issue',
        self::UNCLASSIFIED => 'Unclassified',
    ];

    private const CODE_PATTERNS = [
        'authorization_timeout' => [
            'authorization_timeout',
            'authorisation_timeout',
            'callback_processing',
            'customer_timeout',
            'request_timeout',
            'stk_timeout',
            'user_timeout',
        ],
        'customer_declined' => [
            'customer_cancelled',
            'customer_canceled',
            'customer_declined',
            'customer_rejected',
            'request_cancelled_by_user',
            'request_canceled_by_user',
            'user_cancelled',
            'user_canceled',
            'user_declined',
            'user_rejected',
        ],
        'insufficient_funds' => [
            'balance_too_low',
            'insufficient_balance',
            'insufficient_fund',
            'insufficient_funds',
            'not_enough_funds',
        ],
        'invalid_phone_account' => [
            'account_invalid',
            'invalid_account',
            'invalid_msisdn',
            'invalid_phone',
            'invalid_subscriber',
            'payer_not_found',
            'subscriber_not_found',
        ],
        'provider_network_unavailable' => [
            'connection_error',
            'gateway_unavailable',
            'network_error',
            'network_unavailable',
            'operator_unavailable',
            'provider_unavailable',
            'service_unavailable',
            'upstream_error',
        ],
        'limits_compliance' => [
            'account_blocked',
            'amount_limit',
            'compliance_rejected',
            'daily_limit',
            'kyc_required',
            'limit_exceeded',
            'risk_rejected',
            'transaction_limit',
        ],
        'configuration_routing' => [
            'configuration_error',
            'credentials_missing',
            'invalid_configuration',
            'merchant_not_configured',
            'preflight_exception',
            'provider_not_configured',
            'provider_not_supported',
            'route_not_found',
            'routing_error',
        ],
    ];

    private const MESSAGE_PATTERNS = [
        'authorization_timeout' => [
            'customer did not authorize',
            'customer did not authorise',
            'did not authorize the payment in time',
            'did not authorise the payment in time',
            'authorization timed out',
            'authorisation timed out',
            'approval timed out',
            'prompt timed out',
            'transaction timed out at the mobile network operator',
        ],
        'customer_declined' => [
            'cancelled by customer',
            'canceled by customer',
            'customer cancelled',
            'customer canceled',
            'customer declined',
            'customer rejected',
            'declined by customer',
            'rejected by customer',
            'request cancelled by user',
            'request canceled by user',
            'user cancelled',
            'user canceled',
            'user declined',
            'user rejected',
        ],
        'insufficient_funds' => [
            'balance is too low',
            'insufficient balance',
            'insufficient fund',
            'insufficient funds',
            'not enough funds',
        ],
        'invalid_phone_account' => [
            'account is invalid',
            'account not found',
            'invalid account',
            'invalid mobile number',
            'invalid msisdn',
            'invalid phone',
            'invalid subscriber',
            'payer not found',
            'subscriber not found',
            'unknown subscriber',
        ],
        'provider_network_unavailable' => [
            'connection refused',
            'connection reset',
            'gateway unavailable',
            'mobile network unavailable',
            'network error',
            'network is unavailable',
            'network unavailable',
            'operator unavailable',
            'provider is unavailable',
            'provider timeout',
            'provider unavailable',
            'service unavailable',
            'upstream connection',
            'upstream provider',
        ],
        'limits_compliance' => [
            'account is blocked',
            'amount exceeds',
            'compliance restriction',
            'daily limit',
            'kyc required',
            'limit exceeded',
            'risk restriction',
            'transaction limit',
        ],
        'configuration_routing' => [
            'configuration error',
            'credentials are missing',
            'invalid configuration',
            'merchant is not configured',
            'missing credentials',
            'no active provider',
            'provider is not configured',
            'provider not configured',
            'provider not supported',
            'route is not configured',
            'routing error',
        ],
    ];

    public function classify(array $signals): array
    {
        foreach ($signals['codes'] ?? [] as $code) {
            $category = $this->matchCode($code);
            if ($category !== null) {
                return $this->result($category);
            }
        }

        foreach ($signals['messages'] ?? [] as $message) {
            $category = $this->matchMessage($message);
            if ($category !== null) {
                return $this->result($category);
            }
        }

        return $this->result(self::UNCLASSIFIED);
    }

    private function matchCode(mixed $value): ?string
    {
        $normalized = $this->normalizeCode($value);
        if ($normalized === '') {
            return null;
        }

        foreach (self::CODE_PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if ($normalized === $pattern || str_contains($normalized, $pattern)) {
                    return $category;
                }
            }
        }

        return null;
    }

    private function matchMessage(mixed $value): ?string
    {
        $normalized = $this->normalizeMessage($value);
        if ($normalized === '') {
            return null;
        }

        foreach (self::MESSAGE_PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    return $category;
                }
            }
        }

        return null;
    }

    private function normalizeCode(mixed $value): string
    {
        $value = Str::ascii(Str::lower(trim((string) $value)));

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $value), '_');
    }

    private function normalizeMessage(mixed $value): string
    {
        $value = Str::ascii(Str::lower(trim((string) $value)));

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function result(string $code): array
    {
        return [
            'code' => $code,
            'label' => self::LABELS[$code] ?? self::LABELS[self::UNCLASSIFIED],
            'classified' => $code !== self::UNCLASSIFIED,
        ];
    }
}
