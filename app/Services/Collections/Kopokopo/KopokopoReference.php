<?php

namespace App\Services\Collections\Kopokopo;

/** Exact code normalization at every Kenya spending boundary, including legacy staff entries. */
class KopokopoReference
{
    public static function normalize(?string $value): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim((string) $value)));
    }

    public static function root(?string $value): string
    {
        return preg_replace('~[-/][1-9][0-9]*$~D', '', self::normalize($value));
    }

    public static function matches(?string $value, array $references): bool
    {
        $value = self::normalize($value);
        foreach ($references as $ref) {
            if (preg_match('~^'.preg_quote(self::normalize($ref), '~').'(?:[-/][1-9][0-9]*)?$~D', $value)) {
                return true;
            }
        }

        return false;
    }

    public static function expression(string $column): string
    {
        if (! preg_match('/^(?:[a-z_]+\.)?(?:reference_root|transaction_reference|reference_number|reference_norm|reference)$/D', $column)) {
            throw new \InvalidArgumentException('Reference column refused');
        }

        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(TRIM($column),' ',''),CHAR(9),''),CHAR(10),''),CHAR(13),''))";
    }

    /** Add a Kenya-only alternative to existing exact IN conditions. No non-Kenya comparison changes. */
    public static function orNormalized($query, array $columns, iterable $references, string $platformColumn = 'platform_id'): void
    {
        $refs = array_values(array_unique(array_map([self::class, 'normalize'], is_array($references) ? $references : iterator_to_array($references))));
        if (! $refs) {
            return;
        }
        $query->orWhere(function ($q) use ($columns, $refs, $platformColumn) {
            $q->where($platformColumn, 1)->where(function ($q) use ($columns, $refs) {
                foreach ($columns as $column) {
                    $expression = self::expression($column);
                    $q->orWhereIn(\Illuminate\Support\Facades\DB::raw($expression), $refs);
                    if ($q->getConnection()->getDriverName() === 'mysql') {
                        // Compare each stored code once, rather than expanding two LIKE branches per report row.
                        // MariaDB/MySQL retain only the valid positive numeric split suffix supported by matches().
                        $root = \Illuminate\Support\Facades\DB::raw("REGEXP_REPLACE($expression, '[-/][1-9][0-9]*$', '')");
                        $q->orWhereIn($root, $refs);
                    } else {
                        foreach ($refs as $ref) {
                            $q->orWhereRaw($expression.' LIKE ?', [$ref.'-%'])->orWhereRaw($expression.' LIKE ?', [$ref.'/%']);
                        }
                    }
                }
            });
        });
    }
}
