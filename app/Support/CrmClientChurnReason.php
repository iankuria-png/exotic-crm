<?php

namespace App\Support;

/**
 * Canonical churn-reason codes stored on clients.churned_reason_code.
 *
 * Codes come from three upstream sources mapped at stamp time:
 *  - deal_cancelled  → codes from DealDeactivationReason
 *  - deal_expired    → expired_unrenewed (fixed)
 *  - deal_deactivated→ codes from DealDeactivationReason + admin_deactivated fallback
 *  - case_closed     → codes from CrmClientCloseReason (paid clients only)
 */
final class CrmClientChurnReason
{
    // From DealDeactivationReason / deal cancellation
    public const PAYMENT_REVERSED = 'payment_reversed';
    public const INVALID_REFERENCE = 'invalid_reference';
    public const FRAUD_SUSPECTED = 'fraud_suspected';
    public const CUSTOMER_REQUEST = 'customer_request';
    public const DUPLICATE_ENTRY = 'duplicate_entry';

    // Time-based (deal expired without renewal)
    public const EXPIRED_UNRENEWED = 'expired_unrenewed';

    // Admin deactivation with no reason code
    public const ADMIN_DEACTIVATED = 'admin_deactivated';

    // From CrmClientCloseReason (case-closed paid clients)
    public const NOT_SERIOUS = 'not_serious';
    public const NO_RESPONSE = 'no_response';
    public const DECLINED = 'declined';
    public const INVALID_CONTACT = 'invalid_contact';
    public const INAPPROPRIATE = 'inappropriate';
    public const PAYMENT_ISSUE = 'payment_issue';
    public const DUPLICATE = 'duplicate';

    // Generic fallback
    public const OTHER = 'other';

    public const ALL = [
        self::PAYMENT_REVERSED,
        self::INVALID_REFERENCE,
        self::FRAUD_SUSPECTED,
        self::CUSTOMER_REQUEST,
        self::DUPLICATE_ENTRY,
        self::EXPIRED_UNRENEWED,
        self::ADMIN_DEACTIVATED,
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
        self::PAYMENT_REVERSED => 'Payment Reversed',
        self::INVALID_REFERENCE => 'Invalid Reference',
        self::FRAUD_SUSPECTED => 'Fraud Suspected',
        self::CUSTOMER_REQUEST => 'Customer Request',
        self::DUPLICATE_ENTRY => 'Duplicate Entry',
        self::EXPIRED_UNRENEWED => 'Expired (Unrenewed)',
        self::ADMIN_DEACTIVATED => 'Admin Deactivated',
        self::NOT_SERIOUS => 'Not Serious',
        self::NO_RESPONSE => 'No Response',
        self::DECLINED => 'Declined to Proceed',
        self::INVALID_CONTACT => 'Invalid Contact Details',
        self::INAPPROPRIATE => 'Inappropriate Behaviour',
        self::PAYMENT_ISSUE => 'Payment Issue Not Resolved',
        self::DUPLICATE => 'Duplicate Contact',
        self::OTHER => 'Other',
    ];

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }

    /**
     * Map a deal cancellation_reason_code to a churn reason code.
     * Falls back to OTHER for unknown codes.
     */
    public static function fromDealCancellation(?string $cancellationReasonCode): string
    {
        $map = [
            'payment_reversed' => self::PAYMENT_REVERSED,
            'invalid_reference' => self::INVALID_REFERENCE,
            'fraud_suspected' => self::FRAUD_SUSPECTED,
            'customer_request' => self::CUSTOMER_REQUEST,
            'duplicate_entry' => self::DUPLICATE_ENTRY,
            'other' => self::OTHER,
        ];

        return $map[$cancellationReasonCode ?? ''] ?? self::OTHER;
    }

    /**
     * Map a CrmClientCloseReason code to a churn reason code (pass-through).
     */
    public static function fromCloseCase(string $closeReasonCode): string
    {
        $map = [
            'not_serious' => self::NOT_SERIOUS,
            'no_response' => self::NO_RESPONSE,
            'declined' => self::DECLINED,
            'invalid_contact' => self::INVALID_CONTACT,
            'inappropriate' => self::INAPPROPRIATE,
            'payment_issue' => self::PAYMENT_ISSUE,
            'duplicate' => self::DUPLICATE,
            'other' => self::OTHER,
        ];

        return $map[$closeReasonCode] ?? self::OTHER;
    }

    /**
     * Reason code for a time-based expiry (no cancellation_reason_code).
     */
    public static function fromDealExpiry(): string
    {
        return self::EXPIRED_UNRENEWED;
    }

    /**
     * Reason code for admin deactivation with no explicit reason.
     */
    public static function fromAdminDeactivation(?string $cancellationReasonCode): string
    {
        if ($cancellationReasonCode !== null) {
            return self::fromDealCancellation($cancellationReasonCode);
        }

        return self::ADMIN_DEACTIVATED;
    }
}
