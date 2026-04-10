<?php

namespace App\Support;

enum DealDeactivationReason: string
{
    case PAYMENT_REVERSED = 'payment_reversed';
    case INVALID_REFERENCE = 'invalid_reference';
    case FRAUD_SUSPECTED = 'fraud_suspected';
    case CUSTOMER_REQUEST = 'customer_request';
    case DUPLICATE_ENTRY = 'duplicate_entry';
    case OTHER = 'other';

    public function defaultLinkedPaymentAction(): LinkedPaymentAction
    {
        return match ($this) {
            self::PAYMENT_REVERSED => LinkedPaymentAction::REVERSE,
            self::INVALID_REFERENCE => LinkedPaymentAction::INVALIDATE,
            default => LinkedPaymentAction::NONE,
        };
    }

    public function shouldFlagClientHighRisk(): bool
    {
        return match ($this) {
            self::PAYMENT_REVERSED,
            self::FRAUD_SUSPECTED => true,
            default => false,
        };
    }
}
