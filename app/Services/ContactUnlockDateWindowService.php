<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;

/**
 * Applies calendar-date filters to visitor unlock data in the viewer's timezone.
 *
 * Dates in the Web Visitors controls are calendar dates, while timestamps are stored in UTC.
 * Keeping the conversion here prevents the overview, trail and export from disagreeing with
 * the pulse and analytics endpoints around midnight in non-UTC timezones.
 */
class ContactUnlockDateWindowService
{
    public function apply(Builder $query, string|Expression $column, array $filters): void
    {
        [$from, $to] = $this->bounds($filters);

        if ($from !== null) {
            $query->where($column, '>=', $from);
        }

        if ($to !== null) {
            $query->where($column, '<=', $to);
        }
    }

    /**
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    public function bounds(array $filters): array
    {
        $timezone = (string) ($filters['timezone'] ?? config('app.timezone', 'UTC'));

        return [
            ! empty($filters['from'])
                ? CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc()
                : null,
            ! empty($filters['to'])
                ? CarbonImmutable::parse($filters['to'], $timezone)->endOfDay()->utc()
                : null,
        ];
    }
}
