<?php

namespace App\Services\Collections;

use App\Support\PhoneNormalizer;

/**
 * Ghana MTN MoMo receiving layouts reviewed from the office wallet's own inbox (18 Nov 2026).
 * Credits carry no provider timestamp: the device receive time is the payment time.
 * Upload authenticity is separate from funds evidence.
 */
class GhMtnMomoParser
{
    public const VERSION = 'gh-mtn-momo-v1';

    public const SENDER = 'MobileMoney';

    private const CREDIT = '/^(Payment received|Cash In received) for GHS\s*((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?) from (.*?)\.?\s+Current Balance:?\s*GHS\s*((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)\s*\.?\s+Available Balance/is';

    public function parse(string $sender, string $body): array
    {
        if ($sender !== self::SENDER) {
            return ['kind' => 'unsupported', 'reason' => 'sender_not_supported'];
        }
        // Holds, reversals and revocations win over credit-looking phrases, in any delivery order.
        if (preg_match('/^The reversal request has been rejected for the transaction to/i', $body)) {
            return ['kind' => 'unsupported', 'reason' => 'outgoing_reversal_request'];
        }
        if (preg_match('/^The reversal of the transfer (\d{9,13}) has been revoked/i', $body, $ref)) {
            // The named transfer is the ORIGINAL credit; funds were restored. Flag only, never re-grant.
            return ['kind' => 'unsupported', 'reason' => 'reversal_revoked', 'reference' => $ref[1]];
        }
        if (preg_match('/transfer of GHS\s*([\d,.]+) from (\d{9,13}) has been (withheld|reversed)/i', $body, $hold)) {
            // The case ID in a completed notice is a NEW transaction, not the original credit, so the
            // notice is retained for staff (amount and payer phone) without attaching to a receipt.
            return ['kind' => 'hold', 'reference' => null, 'reason' => strtolower($hold[3]) === 'reversed' ? 'reversal_completed' : 'reversal_or_hold',
                'principal_minor' => $this->minorOrNull($hold[1]), 'payer_phone' => $this->phone($hold[2])];
        }
        if (! preg_match(self::CREDIT, $body, $credit)) {
            return ['kind' => 'unsupported', 'reason' => $this->looksLikeCredit($body) ? 'unrecognized_credit' : 'unrecognized_layout'];
        }
        if (! preg_match('/Transaction ID:\s*(\d{9,13})\b/i', $body, $ref)) {
            return ['kind' => 'unsupported', 'reason' => 'reference_missing'];
        }
        $minor = $this->minorOrNull($credit[2]);
        if (! $minor) {
            return ['kind' => 'unsupported', 'reason' => 'invalid_principal_or_time'];
        }
        $cashIn = stripos($credit[1], 'Cash In') === 0;
        $payerReference = $cashIn ? null : $this->payerReference($body);
        $phones = $payerReference ? $this->referencePhones($payerReference) : [];
        if (count($phones) > 1) {
            return ['kind' => 'unsupported', 'reason' => 'multiple_payer_phones'];
        }

        return ['kind' => 'credit', 'reference' => $ref[1], 'reference_raw' => $ref[1], 'principal_minor' => $minor, 'currency' => 'GHS',
            'payer_phone' => $phones[0] ?? null, 'provider_at' => null, 'time_source' => 'device_received',
            'family' => $cashIn ? 'cash_in' : 'payment_received', 'version' => self::VERSION];
    }

    /** Display evidence only: never changes the financial parser or grants identity authority. */
    public function payerDetails(string $sender, string $body): array
    {
        $credit = $this->parse($sender, $body);
        $empty = ['name' => null, 'party' => null, 'reference' => null, 'phone' => null];
        if (($credit['kind'] ?? '') !== 'credit' || ! preg_match(self::CREDIT, $body, $match)) {
            return $empty;
        }
        $party = trim(preg_replace('/\s+/u', ' ', $match[3]), " \t\n\r\0\x0B.");
        $name = $party;
        // A Cash In names the depositing agent business, never the paying customer.
        if ($credit['family'] === 'cash_in' || ! preg_match('/^\p{L}[\p{L}\p{M}\s\x{2019}\x{0027}.\-]{1,179}$/uD', $name)
            || preg_match('/\b(?:enterprise|ventures|bank|momo|mobile money|interest|limited|ltd|company)\b/iu', $name)) {
            $name = null;
        }

        return ['name' => $name, 'party' => mb_substr($party, 0, 180), 'reference' => $credit['reference'], 'phone' => $credit['payer_phone'],
            'payer_reference' => $credit['family'] === 'payment_received' ? $this->payerReference($body) : null];
    }

    /** Receiving-wallet balance stated after the credit ("Current Balance: GHS 2353.86"). */
    public function balanceMinor(string $body): ?int
    {
        if (! preg_match('/Current Balance:?\s*GHS\s*((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)/i', $body, $match)) {
            return null;
        }

        return $this->minorOrNull($match[1]);
    }

    /** A provider message that reads as an incoming payment but did not parse is template drift. */
    public function looksLikeCredit(string $body): bool
    {
        return (bool) preg_match('/^(?:Payment|Cash In)\s+received\b.*\bGHS\b/is', $body);
    }

    public function phone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (strlen($digits) === 9) {
            $digits = '0'.$digits; // Cross-network references sometimes drop the trunk zero.
        }
        $normalized = PhoneNormalizer::normalize($digits, '233');

        return preg_match('/^233[25]\d{8}$/D', (string) $normalized) ? $normalized : null;
    }

    private function payerReference(string $body): ?string
    {
        return preg_match('/Reference:\s*(.*?)\.\s*Transaction ID:/is', $body, $m) ? trim($m[1]) : null;
    }

    /** "NAME ,233xxxxxxxxx,note from VODAFONE" — the sending network's own payer number. */
    private function referencePhones(string $reference): array
    {
        if (! preg_match('/,\s*(\+?\d{9,12})\s*,.*\bfrom\s+[A-Z]+\s*$/i', $reference, $m)) {
            return [];
        }

        return array_values(array_filter([$this->phone($m[1])]));
    }

    private function minorOrNull(string $amount): ?int
    {
        try {
            $minor = app(TzMpesaParser::class)->minor(rtrim($amount, '.'));
        } catch (CollectionException) {
            return null;
        }

        return $minor > 0 ? $minor : null;
    }
}
