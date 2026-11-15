<?php

namespace App\Support;

/**
 * Why staff locked a profile. A lock keeps the page published and indexed but
 * hides contacts, so the reason is what tells the next agent why leads stopped.
 */
final class ClientLockReason
{
    public const VERIFICATION = 'verification_required';

    public const POLICY = 'policy_violation';

    public const FRAUD = 'fraud_or_dispute';

    public const CLIENT_REQUEST = 'client_request';

    public const QUALITY = 'quality_review';

    public const OTHER = 'other';

    public const ALL = [
        self::VERIFICATION,
        self::POLICY,
        self::FRAUD,
        self::CLIENT_REQUEST,
        self::QUALITY,
        self::OTHER,
    ];

    public const LABELS = [
        self::VERIFICATION => 'Verification needed',
        self::POLICY => 'Policy or content violation',
        self::FRAUD => 'Fraud or payment dispute',
        self::CLIENT_REQUEST => 'Client asked for it',
        self::QUALITY => 'Profile quality review',
        self::OTHER => 'Other',
    ];

    public const DESCRIPTIONS = [
        self::VERIFICATION => 'KYC, photos or identity still need checking.',
        self::POLICY => 'Content, photos or behaviour break the rules.',
        self::FRAUD => 'Chargeback, fake payment or suspected scam.',
        self::CLIENT_REQUEST => 'The advertiser wants contacts hidden for now.',
        self::QUALITY => 'Bio, photos or details need fixing first.',
        self::OTHER => 'Explain in the note.',
    ];

    public static function isValid(string $code): bool
    {
        return in_array($code, self::ALL, true);
    }

    public static function requiresNote(string $code): bool
    {
        return $code === self::OTHER;
    }

    public static function label(string $code): string
    {
        return self::LABELS[$code] ?? $code;
    }

    /** @return list<array{code: string, label: string, description: string, requires_note: bool}> */
    public static function options(): array
    {
        return array_map(static fn (string $code) => [
            'code' => $code,
            'label' => self::LABELS[$code],
            'description' => self::DESCRIPTIONS[$code],
            'requires_note' => self::requiresNote($code),
        ], self::ALL);
    }
}
