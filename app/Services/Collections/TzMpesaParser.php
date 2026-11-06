<?php

namespace App\Services\Collections;

use DateTimeImmutable;
use DateTimeZone;

/** Reviewed receiving layouts only. Upload authenticity is separate from funds evidence. */
class TzMpesaParser
{
    public const VERSION = 'tz-mpesa-v1';

    public function parse(string $sender, string $body): array
    {
        if ($sender !== 'M-PESA') {
            return ['kind' => 'unsupported', 'reason' => 'sender_not_supported'];
        }
        // Reversals and holds win over credit-looking phrases, in any delivery order.
        if (preg_match('/revers|rudish|rejesh/i', $body)) {
            preg_match('/(?:transaction\s+|Muamala wa awali:\s*)([A-Z0-9]{10,11})\b/i', $body, $ref);

            return ['kind' => 'hold', 'reference' => isset($ref[1]) ? strtoupper($ref[1]) : null,
                'reason' => preg_match('/has been successfully reversed|kimerudishwa kikamilifu/i', $body) ? 'reversal_completed' : 'reversal_or_hold'];
        }
        if (! preg_match('/^([A-Z0-9]{10,11})\s+(?:confirmed|imethibitishwa)\.\s*/i', $body, $ref)) {
            return ['kind' => 'unsupported', 'reason' => 'unrecognized_layout'];
        }
        $family = match (true) {
            (bool) preg_match('/^\S+\s+imethibitishwa\.\s*Umepokea\s+Tshs?\s*/i', $body) => 'sw_received',
            (bool) preg_match('/^\S+\s+confirmed\.\s*You have received a payment of\s+Tshs?\s*/i', $body) => 'en_payment',
            (bool) preg_match('/^\S+\s+confirmed\.\s*You have received\s+Tshs?\s*/i', $body) => 'en_received',
            (bool) preg_match('/^\S+\s+confirmed\.\s*On\s+\d.*?\bReceive\s+Tshs?\s*/i', $body) => 'en_date_first',
            default => null,
        };
        if (! $family || ! preg_match('/(?:You have received\s+(?:a payment of\s+)?|Umepokea\s+|Receive\s+)Tshs?\s*((?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d{1,2})?)(?![\d.,])/i', $body, $amount)
            || ! preg_match('/(?:from|kutoka)\s+(.+?)(?=\s+(?:on|tarehe)\s+|\s+and\s+(?:balance|charged)|\s+(?:New\s+)?(?:M-Pesa\s+)?balance\s|$)/i', $body, $party)
            || ! preg_match('/(?:on|tarehe)\s+(\d{1,2}[\/-]\d{1,2}[\/-]\d{2,4})(?:,\s*|\s+(?:at|saa)\s+|\s+)(\d{1,2}:\d{2}(?::\d{2})?)(?:\s*([AP]M))?/i', $body, $date)) {
            return ['kind' => 'unsupported', 'reason' => 'unrecognized_credit'];
        }
        $minor = $this->minor($amount[1]);
        $dateString = str_replace('-', '/', $date[1]);
        $format = strlen(explode('/', $dateString)[2]) === 4 ? 'j/n/Y' : 'j/n/y';
        $format .= ' '.(! empty($date[3]) ? 'g' : 'G').':i'.(substr_count($date[2], ':') === 2 ? ':s' : '');
        $input = $dateString.' '.$date[2];
        if (! empty($date[3])) {
            $format .= ' A';
            $input .= ' '.strtoupper($date[3]);
        }
        $time = DateTimeImmutable::createFromFormat('!'.$format, $input, new DateTimeZone('Africa/Dar_es_Salaam'));
        $errors = DateTimeImmutable::getLastErrors();
        if (! $time || ($errors && ($errors['warning_count'] || $errors['error_count'])) || $minor <= 0) {
            return ['kind' => 'unsupported', 'reason' => 'invalid_principal_or_time'];
        }
        preg_match_all('/(?<!\d)(?:255[67]\d{8}|0[67]\d{8})(?!\d)/', $party[1], $phones);
        $phones = array_values(array_unique(array_filter(array_map(fn ($p) => app(CollectionPrivacy::class)->tzPhone($p), $phones[0]))));
        if (count($phones) > 1) {
            return ['kind' => 'unsupported', 'reason' => 'multiple_payer_phones'];
        }

        return ['kind' => 'credit', 'reference' => strtoupper($ref[1]), 'reference_raw' => $ref[1],
            'principal_minor' => $minor, 'currency' => 'TZS', 'payer_phone' => $phones[0] ?? null,
            'provider_at' => $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'family' => $family, 'version' => self::VERSION];
    }

    /** Display evidence only: never changes the financial parser or grants identity authority. */
    public function payerDetails(string $sender, string $body): array
    {
        $credit = $this->parse($sender, $body);
        $empty = ['name' => null, 'party' => null, 'reference' => null, 'phone' => null];
        if (($credit['kind'] ?? '') !== 'credit'
            || ! preg_match('/(?:from|kutoka)\s+(.+?)(?=\s+(?:on|tarehe)\s+|\s+and\s+(?:balance|charged)|\s+(?:New\s+)?(?:M-Pesa\s+)?balance\s|$)/iu', $body, $match)) {
            return $empty;
        }
        $party = trim(preg_replace('/\s+/u', ' ', $match[1]), " \t\n\r\0\x0B.");
        $name = $party;
        // A bank-prefixed detail message supplies the person AFTER the full phone.
        if ($credit['payer_phone']) {
            $name = preg_replace('/^.*?(?:\+?255[67]\d{8}|0[67]\d{8})\s*[-:]?\s*/u', '', $party);
        }
        $name = trim($name, " \t\n\r\0\x0B.-:");
        if (! preg_match('/^\p{L}[\p{L}\p{M}\s\x{2019}\x{0027}.\-]{1,179}$/uD', $name)
            || preg_match('/\b(?:bank|benki|mpesa|m-pesa|wallet|crdb|nmb|nbc|equity|tigo|airtel)\b/iu', $name)) {
            $name = null;
        }

        return ['name' => $name, 'party' => mb_substr($party, 0, 180), 'reference' => $credit['reference'], 'phone' => $credit['payer_phone']];
    }

    public function minor(string $amount): int
    {
        $parts = explode('.', str_replace(',', '', $amount));
        if (! ctype_digit($parts[0]) || strlen($parts[0]) > 12 || (isset($parts[1]) && ! preg_match('/^\d{1,2}$/D', $parts[1]))) {
            throw new CollectionException('invalid_minor_amount', 422);
        }

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }
}
