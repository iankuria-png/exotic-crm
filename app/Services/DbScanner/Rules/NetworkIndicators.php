<?php

namespace App\Services\DbScanner\Rules;

/** Exact addresses and explicit CIDRs only; never textual prefix matching. */
final class NetworkIndicators
{
    public static function matches(string $ip, array $entries): bool
    {
        $address = @inet_pton($ip);
        if ($address === false) {
            return false;
        }
        foreach ($entries as $entry) {
            [$network, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            $packed = @inet_pton($network);
            if ($packed === false || strlen($packed) !== strlen($address)) {
                continue;
            }
            if ($bits === null) {
                if ($packed === $address) {
                    return true;
                }

                continue;
            }
            if (! ctype_digit($bits) || (int) $bits > strlen($address) * 8) {
                continue;
            }
            $bytes = intdiv((int) $bits, 8);
            $tail = (int) $bits % 8;
            if (substr($packed, 0, $bytes) === substr($address, 0, $bytes)
                && ($tail === 0 || ((ord($packed[$bytes]) ^ ord($address[$bytes])) & (255 << (8 - $tail))) === 0)) {
                return true;
            }
        }

        return false;
    }
}
