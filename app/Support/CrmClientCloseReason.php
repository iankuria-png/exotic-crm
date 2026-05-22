<?php

namespace App\Support;

final class CrmClientCloseReason
{
    public const NOT_SERIOUS = 'not_serious';
    public const NO_RESPONSE = 'no_response';
    public const DECLINED = 'declined';
    public const INVALID_CONTACT = 'invalid_contact';
    public const INAPPROPRIATE = 'inappropriate';
    public const PAYMENT_ISSUE = 'payment_issue';
    public const DUPLICATE = 'duplicate';
    public const OTHER = 'other';

    public const ALL = [
        self::NOT_SERIOUS,
        self::NO_RESPONSE,
        self::DECLINED,
        self::INVALID_CONTACT,
        self::INAPPROPRIATE,
        self::PAYMENT_ISSUE,
        self::DUPLICATE,
        self::OTHER,
    ];

    public const LABELS = [
        self::NOT_SERIOUS => 'Not Serious',
        self::NO_RESPONSE => 'No Response',
        self::DECLINED => 'Declined to Proceed',
        self::INVALID_CONTACT => 'Invalid Contact Details',
        self::INAPPROPRIATE => 'Inappropriate Behaviour',
        self::PAYMENT_ISSUE => 'Payment Issue Not Resolved',
        self::DUPLICATE => 'Duplicate Contact',
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

    /**
     * Return [{code, label, requires_note}, ...] for FE dropdowns.
     */
    public static function options(): array
    {
        return array_map(
            fn (string $code) => [
                'code' => $code,
                'label' => self::LABELS[$code],
                'requires_note' => self::requiresNote($code),
            ],
            self::ALL
        );
    }

    /**
     * Map legacy PaymentQueueController::manualClose() `category` values
     * to canonical reason codes. Kept here so the mapping is reviewable
     * alongside the canonical taxonomy.
     */
    public static function fromLegacyPaymentCategory(?string $category): ?string
    {
        return match ($category) {
            'timeout' => self::PAYMENT_ISSUE,
            'customer_cancelled' => self::DECLINED,
            'duplicate_request' => self::DUPLICATE,
            'fraud_suspected' => self::INAPPROPRIATE,
            'other' => self::OTHER,
            default => null,
        };
    }
}
