<?php

namespace App\Helpers;

use Illuminate\Support\Facades\DB;

class CurrencyBreakdown
{
    /**
     * Compute a per-currency breakdown from an Eloquent builder scoped to the
     * payments table.  The caller should pass a *base* query (WHERE clauses
     * already applied, no explicit SELECT or GROUP BY set yet).  This method
     * clones it, replaces the SELECT with the two aggregate expressions needed,
     * adds a GROUP BY on the resolved currency, and returns a canonical result.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $defaultCurrency  Final fallback when both the row currency
     *                                   and the market currency are unavailable
     * @return array{breakdown: array<string,float>, currency_count: int, scalar_amount: float|null}
     */
    public static function fromPaymentQuery($query, string $defaultCurrency = 'KES'): array
    {
        $fallback = self::escapeLiteral($defaultCurrency);
        $currencyExpression = "COALESCE(payments.currency, (SELECT currency_code FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1), '{$fallback}')";

        return self::run(clone $query, $currencyExpression, 'payments.amount');
    }

    /**
     * Compute a per-currency breakdown from a Deal Eloquent builder using:
     * deal currency -> market currency -> explicit fallback.
     */
    public static function fromDealQuery($query, string $defaultCurrency = 'KES'): array
    {
        $fallback = self::escapeLiteral($defaultCurrency);
        $currencyExpression = "COALESCE(deals.currency, (SELECT currency_code FROM platforms WHERE platforms.id = deals.platform_id LIMIT 1), '{$fallback}')";

        return self::run(clone $query, $currencyExpression, 'deals.amount');
    }

    /**
     * @return array{breakdown: array<string,float>, currency_count: int, scalar_amount: float|null}
     */
    private static function run($query, string $currencyExpression, string $amountExpression): array
    {
        $rows = $query
            ->selectRaw("{$currencyExpression} as _c")
            ->selectRaw("SUM({$amountExpression}) as _t")
            ->groupBy('_c')
            ->get();

        $breakdown = [];
        foreach ($rows as $row) {
            $breakdown[$row->_c] = (float) $row->_t;
        }
        ksort($breakdown); // stable alphabetical order for consistent API responses

        $count = count($breakdown);

        return [
            'breakdown'      => $breakdown,
            'currency_count' => $count,
            'scalar_amount'  => $count === 1 ? array_values($breakdown)[0] : null,
        ];
    }

    private static function escapeLiteral(string $currency): string
    {
        $normalized = strtoupper(trim($currency));

        if ($normalized === '') {
            $normalized = 'KES';
        }

        return str_replace("'", "''", $normalized);
    }
}
