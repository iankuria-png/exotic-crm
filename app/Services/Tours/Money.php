<?php

namespace App\Services\Tours;

final class Money
{
    public static function factor(string $currency): int
    {
        abort_if(in_array($currency, ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND', 'CLF', 'UYW'], true), 422, 'Tours payments do not yet support currencies with more than two decimal places.');

        return in_array($currency, ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'], true) ? 1 : 100;
    }
}
