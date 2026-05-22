<?php

namespace App\Support;

final class CrmPaymentCloseReason
{
    public const CUSTOMER_CONVERTED = 'customer_converted';
    public const PAYMENT_FAILED = 'payment_failed';
    public const CUSTOMER_TESTING = 'customer_testing';
    public const SYSTEMS_DOWN = 'systems_down';
    public const DUPLICATE_ATTEMPT = 'duplicate_attempt';
    public const CUSTOMER_ABANDONED = 'customer_abandoned';
    public const OTHER = 'other';

    public const ALL = [
        self::CUSTOMER_CONVERTED,
        self::PAYMENT_FAILED,
        self::CUSTOMER_TESTING,
        self::SYSTEMS_DOWN,
        self::DUPLICATE_ATTEMPT,
        self::CUSTOMER_ABANDONED,
        self::OTHER,
    ];

    public const LABELS = [
        self::CUSTOMER_CONVERTED => 'Customer Converted',
        self::PAYMENT_FAILED => 'Payment Failed',
        self::CUSTOMER_TESTING => 'Customer Was Testing',
        self::SYSTEMS_DOWN => 'Systems Were Down',
        self::DUPLICATE_ATTEMPT => 'Duplicate Attempt',
        self::CUSTOMER_ABANDONED => 'Customer Abandoned',
        self::OTHER => 'Other',
    ];

    public static function requiresNote(string $code): bool
    {
        return $code === self::OTHER;
    }

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }

    public static function isValid(string $code): bool
    {
        return in_array($code, self::ALL, true);
    }

    public static function fromLegacyPaymentCategory(?string $category): ?string
    {
        return match ($category) {
            'timeout' => self::SYSTEMS_DOWN,
            'customer_cancelled' => self::CUSTOMER_ABANDONED,
            'duplicate_request' => self::DUPLICATE_ATTEMPT,
            'fraud_suspected' => self::CUSTOMER_TESTING,
            'other' => self::OTHER,
            default => null,
        };
    }
}
