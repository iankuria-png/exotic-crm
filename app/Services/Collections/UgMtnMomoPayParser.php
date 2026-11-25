<?php

namespace App\Services\Collections;

use App\Support\PhoneNormalizer;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Uganda MTN MoMoPay merchant (Till) receiving layouts reviewed from the office phone's own inbox (25 Nov 2026).
 * Every Till payment arrives as TWO messages sharing one 11-digit Transaction ID: a wallet view with the
 * provider time and fee, and a MoMoPay view with the payer's phone. Both parse to the same receipt reference,
 * so the receipt service merges them. Person-to-person credits into the SIM's personal wallet are not Till
 * payments and are rejected explicitly, never treated as template drift. Upload authenticity is separate.
 */
class UgMtnMomoPayParser
{
    public const VERSION = 'ug-mtn-momopay-v1';

    public const SENDER = 'MTNMobMoney';

    private const AMOUNT = '((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)';

    /** Wallet view (since Aug 2025): "You have received UGX 50000 from NAME at 2026-11-24 17:04:16.  Fee: 500. Message: Till:632555. New balance is: UGX 900680. Transaction ID: 45331931063." */
    private const TILL_WALLET = '/^You have received UGX\s*'.self::AMOUNT.' from (.+?) at (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.\s+Fee:\s*[\d,.]+\.\s*Message:\s*Till\s*:?\s*(\d*)\s*\.\s*New balance is:\s*UGX\s*'.self::AMOUNT.'\.\s*Transaction ID:\s*(\d{9,13})\b/is';

    /** MoMoPay view (since Aug 2025): "You have received UGX 50,000 from (NAME) 256XXXXXXXXX. Your new MoMoPay balance: 900680.46. Transaction ID: 45331931063." */
    private const TILL_MOMOPAY = '/^You have received UGX\s*'.self::AMOUNT.' from \((.*?)\)\s*(\+?\d{9,12})?\.\s*Your new MoMoPay(?:Account)? balance:\s*(?:UGX\s*)?'.self::AMOUNT.'\.\s*(?:Transaction ID|ID):\s*(\d{9,13})\b/is';

    /** Wallet view before Aug 2025: "You have received 60000 UGX from NAME (256…) on your mobile money account at 2025-08-05 20:45:03. Message from sender: Till:632555. Your new balance: 1410121 UGX. … Financial Transaction Id: 34436500280." */
    private const TILL_WALLET_V0 = '/^You have received '.self::AMOUNT.' UGX from (.+?) \((\+?\d{9,12})\) on your mobile money account at (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\.\s*Message from sender:\s*Till\s*:?\s*(\d*)\s*\.\s*Your new balance:\s*'.self::AMOUNT.' UGX\..*?Financial Transaction Id:\s*(\d{9,13})\b/is';

    public function parse(string $sender, string $body): array
    {
        if ($sender !== self::SENDER) {
            return ['kind' => 'unsupported', 'reason' => 'sender_not_supported'];
        }
        $body = trim($body);
        // Reversals win over credit-looking phrases. The notice's own transaction ID is NEW, not the original
        // credit's (verified on the inbox), so it is retained for staff without attaching to a receipt.
        if (preg_match('/^The transaction from (.*?) \((\+?\d{9,12})\) with '.self::AMOUNT.' UGX has been reversed\b/is', $body, $reversed)) {
            return ['kind' => 'hold', 'reference' => null, 'reason' => 'reversal_completed',
                'principal_minor' => $this->minorOrNull($reversed[3]), 'payer_phone' => $this->phone($reversed[2])];
        }
        if (preg_match('/^(.*?),\s*(\+?\d{9,12}) has initiated a reversal of '.self::AMOUNT.' wrongly sent to you/is', $body, $request)) {
            return ['kind' => 'hold', 'reference' => null, 'reason' => 'reversal_or_hold',
                'principal_minor' => $this->minorOrNull($request[3]), 'payer_phone' => $this->phone($request[2])];
        }
        if (preg_match(self::TILL_WALLET, $body, $m)) {
            return $this->credit($m[6], $m[1], $m[2], null, $this->providerAt($m[3]), 'till_wallet');
        }
        if (preg_match(self::TILL_MOMOPAY, $body, $m)) {
            if ($m[3] !== '' && ! $this->phone($m[3])) {
                return ['kind' => 'unsupported', 'reason' => 'invalid_payer_phone'];
            }

            return $this->credit($m[5], $m[1], $m[2], $m[3] !== '' ? $this->phone($m[3]) : null, null, 'till_momopay');
        }
        if (preg_match(self::TILL_WALLET_V0, $body, $m)) {
            return $this->credit($m[7], $m[1], $m[2], $this->phone($m[3]), $this->providerAt($m[4]), 'till_wallet_v0');
        }
        if (preg_match('/^You have received UGX\s*[\d,.]+ from .*?\bon \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\s*(?:fee:\s*[\d,.]+\.\s*)?Reason:/is', $body)) {
            // Person-to-person transfer into the SIM's personal wallet, outside the Till (Ian, 25 Nov 2026).
            return ['kind' => 'unsupported', 'reason' => 'personal_wallet_credit'];
        }
        if (preg_match('/^You have deposited UGX/i', $body)) {
            return ['kind' => 'unsupported', 'reason' => 'personal_wallet_deposit'];
        }

        return ['kind' => 'unsupported', 'reason' => $this->looksLikeCredit($body) ? 'unrecognized_credit' : 'unrecognized_layout'];
    }

    /** Display evidence only: never changes the financial parser or grants identity authority. */
    public function payerDetails(string $sender, string $body): array
    {
        $credit = $this->parse($sender, $body);
        if (($credit['kind'] ?? '') !== 'credit') {
            return ['name' => null, 'party' => null, 'reference' => null, 'phone' => null];
        }
        $party = trim(preg_replace('/\s+/u', ' ', $credit['party']), " \t\n\r\0\x0B.");
        $name = preg_match('/^\p{L}[\p{L}\p{M}\s\x{2019}\x{0027}.\-]{1,179}$/uD', $party)
            && ! preg_match('/\b(?:bank|momo|mobile money|airtel|limited|ltd|company|enterprises?)\b/iu', $party) ? $party : null;

        return ['name' => $name, 'party' => mb_substr($party, 0, 180), 'reference' => $credit['reference'], 'phone' => $credit['payer_phone']];
    }

    /** Receiving balance stated after the credit (wallet view "New balance is: UGX 900680", MoMoPay view "balance: 900680.46"). */
    public function balanceMinor(string $body): ?int
    {
        if (preg_match('/New balance is:\s*UGX\s*'.self::AMOUNT.'/i', $body, $m)
            || preg_match('/Your new MoMoPay(?:Account)? balance:\s*(?:UGX\s*)?'.self::AMOUNT.'\./i', $body, $m)
            || preg_match('/Your new balance:\s*'.self::AMOUNT.' UGX/i', $body, $m)) {
            return $this->minorOrNull($m[1], true);
        }

        return null;
    }

    /** A provider message that reads as an incoming Till payment but did not parse is template drift. */
    public function looksLikeCredit(string $body): bool
    {
        $body = trim($body);
        if (preg_match('/\bReason:|^You have deposited/i', $body)) {
            return false; // Personal-wallet layouts are classified, not drift.
        }

        return (bool) preg_match('/^You have received\b.*\bUGX\b/is', $body);
    }

    public function phone(?string $phone): ?string
    {
        $normalized = PhoneNormalizer::normalize((string) $phone, '256');

        // MTN Uganda mobile money numbers use the 07x and newer 039x ranges.
        return preg_match('/^256(?:7\d|39)\d{7}$/D', (string) $normalized) ? $normalized : null;
    }

    private function credit(string $reference, string $amount, string $party, ?string $phone, ?string $providerAt, string $family): array
    {
        $minor = $this->minorOrNull($amount);
        if (! $minor || ($family !== 'till_momopay' && ! $providerAt)) {
            return ['kind' => 'unsupported', 'reason' => 'invalid_principal_or_time'];
        }

        // The MoMoPay view has no provider clock; its paired wallet view supplies it to the same receipt.
        return ['kind' => 'credit', 'reference' => $reference, 'reference_raw' => $reference, 'principal_minor' => $minor, 'currency' => 'UGX',
            'payer_phone' => $phone, 'provider_at' => $providerAt, 'time_source' => $providerAt ? 'provider' : 'paired_view',
            'family' => $family, 'party' => $party, 'version' => self::VERSION];
    }

    private function providerAt(string $local): ?string
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $local, new DateTimeZone('Africa/Kampala'));
        $errors = DateTimeImmutable::getLastErrors();

        return $time && ! ($errors && ($errors['warning_count'] || $errors['error_count']))
            ? $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
    }

    private function minorOrNull(string $amount, bool $allowZero = false): ?int
    {
        try {
            $minor = app(TzMpesaParser::class)->minor(rtrim($amount, '.'));
        } catch (CollectionException) {
            return null;
        }

        return $minor > 0 || ($allowZero && $minor === 0) ? $minor : null;
    }
}
