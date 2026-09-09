<?php

namespace App\Services;

use App\Models\ContactUnlockEvent;
use App\Models\Payment;
use App\Models\VisitorContactUnlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Trend + market breakdown for visitor contact-unlock revenue.
 *
 * Deliberately separate from the advertiser subscription books: every query here is scoped by
 * Payment::contactUnlockRevenue(), so nothing this service returns is ever double-counted against
 * the CEO dashboard's collected revenue (which runs excludingWalletTopups() and therefore drops
 * visitor_contact_unlock rows entirely).
 */
class ContactUnlockAnalyticsService
{
    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService
    ) {}

    public function analytics(
        int|array|null $platformIds = null,
        string $range = 'today',
        ?string $timezone = null,
        ?string $targetCurrency = null,
        ?string $fromDate = null,
        ?string $toDate = null,
        string $bucket = 'auto'
    ): array {
        [$from, $to] = $this->window($range, $timezone, $fromDate, $toDate);
        $length = max(1, $from->diffInSeconds($to));
        $priorTo = $from->subSecond();
        $priorFrom = $priorTo->subSeconds($length);
        $target = $this->reportingCurrencyService->resolveTargetCurrency($targetCurrency);
        $days = max(1, (int) ceil($length / 86400));
        $resolvedBucket = $this->resolveBucket($bucket, $days);

        $currentRows = $this->revenueRows($platformIds, $from, $to, $target);
        $priorRows = $this->revenueRows($platformIds, $priorFrom, $priorTo, $target);

        $currentTotals = $this->totalsFor($currentRows, $target);
        $priorTotals = $this->totalsFor($priorRows, $target);

        $unlockCounts = $this->unlockCountsByPlatform($platformIds, $from, $to);
        $eventCounts = $this->eventCountsByPlatform($platformIds, $from, $to);

        return [
            'window' => [
                'range' => $range,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'prior_from' => $priorFrom->toIso8601String(),
                'prior_to' => $priorTo->toIso8601String(),
                'days' => $days,
                'bucket' => $resolvedBucket,
                'target_currency' => $target,
            ],
            'totals' => [
                'normalized_total' => $currentTotals['normalized_total'],
                'normalized_currency' => $target,
                'source_breakdown' => $currentTotals['source_breakdown'],
                'normalization_meta' => $currentTotals['normalization_meta'],
                'payments_count' => $currentTotals['payments_count'],
                'unlocks_count' => (int) array_sum($unlockCounts),
                'prior_normalized_total' => $priorTotals['normalized_total'],
                'prior_payments_count' => $priorTotals['payments_count'],
                'delta_percent' => $this->percentDelta($currentTotals['normalized_total'], $priorTotals['normalized_total']),
                'average_order_value' => $currentTotals['payments_count'] > 0 && $currentTotals['normalized_total'] !== null
                    ? round((float) $currentTotals['normalized_total'] / $currentTotals['payments_count'], 2)
                    : null,
            ],
            'points' => $this->points($currentRows, $priorRows, $from, $to, $priorFrom, $priorTo, $resolvedBucket, $target),
            'markets' => $this->markets($currentRows, $priorRows, $unlockCounts, $eventCounts, $target),
        ];
    }

    /**
     * One aggregate row per (date, platform, currency) — the shape ReportingCurrencyService
     * normalizes, plus the payment count it does not track.
     */
    private function revenueRows(int|array|null $platformIds, CarbonImmutable $from, CarbonImmutable $to, string $target): Collection
    {
        $dateExpression = $this->dateExpression();
        $query = Payment::query()
            ->contactUnlockRevenue()
            ->whereIn('payments.status', Payment::SUCCESSFUL_STATUSES)
            ->whereBetween(DB::raw($this->eventExpression()), [$from, $to])
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->selectRaw("{$dateExpression} as event_date")
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw('platforms.currency_code as platform_currency')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, '{$target}') as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(*) as payments_count')
            ->groupByRaw($dateExpression)
            ->groupBy('payments.platform_id', 'platforms.name', 'platforms.country', 'platforms.currency_code')
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, '{$target}')");

        $this->applyPlatformScope($query, $platformIds, 'payments.platform_id');

        return $query->get();
    }

    private function totalsFor(Collection $rows, string $target): array
    {
        $normalized = $this->reportingCurrencyService->normalizeEventRows($rows, $target, false);

        return [
            'normalized_total' => $normalized['normalized_total'],
            'source_breakdown' => $normalized['source_breakdown'],
            'normalization_meta' => $normalized['normalization_meta'],
            'payments_count' => (int) $rows->sum('payments_count'),
        ];
    }

    private function points(
        Collection $currentRows,
        Collection $priorRows,
        CarbonImmutable $from,
        CarbonImmutable $to,
        CarbonImmutable $priorFrom,
        CarbonImmutable $priorTo,
        string $bucket,
        string $target
    ): array {
        $current = $this->bucketed($currentRows, $from, $to, $bucket, $target);
        $prior = array_values($this->bucketed($priorRows, $priorFrom, $priorTo, $bucket, $target));

        return collect(array_values($current))
            ->map(function (array $point, int $index) use ($prior) {
                $priorPoint = $prior[$index] ?? null;

                return [
                    ...$point,
                    'prior_label' => $priorPoint['label'] ?? null,
                    'prior_value' => $priorPoint['value'] ?? 0,
                    'prior_payments_count' => $priorPoint['payments_count'] ?? 0,
                    'prior_average_ticket' => $priorPoint['average_ticket'] ?? 0,
                    'delta_percent' => $this->percentDelta($point['value'], $priorPoint['value'] ?? null),
                ];
            })
            ->all();
    }

    private function bucketed(Collection $rows, CarbonImmutable $from, CarbonImmutable $to, string $bucket, string $target): array
    {
        $buckets = [];
        $cursor = $this->bucketStart($from, $bucket);
        $end = $this->bucketStart($to, $bucket);

        while ($cursor->lte($end)) {
            $buckets[$this->bucketLabel($cursor, $bucket)] = collect();
            $cursor = match ($bucket) {
                'month' => $cursor->addMonth(),
                'week' => $cursor->addWeek(),
                default => $cursor->addDay(),
            };
        }

        foreach ($rows as $row) {
            $label = $this->bucketLabel($this->bucketStart(CarbonImmutable::parse((string) $row->event_date), $bucket), $bucket);
            if (array_key_exists($label, $buckets)) {
                $buckets[$label] = $buckets[$label]->push($row);
            }
        }

        $points = [];
        foreach ($buckets as $label => $bucketRows) {
            $normalized = $this->reportingCurrencyService->normalizeEventRows($bucketRows, $target, false);
            $count = (int) $bucketRows->sum('payments_count');
            $value = (float) ($normalized['normalized_total'] ?? 0);

            $points[$label] = [
                'label' => $label,
                'value' => $value,
                'payments_count' => $count,
                'average_ticket' => $count > 0 ? round($value / $count, 2) : 0,
                'source_breakdown' => $normalized['source_breakdown'],
            ];
        }

        return $points;
    }

    private function markets(Collection $currentRows, Collection $priorRows, array $unlockCounts, array $eventCounts, string $target): array
    {
        $priorByPlatform = $priorRows
            ->groupBy(fn ($row) => (int) $row->platform_id)
            ->map(fn (Collection $group) => (float) ($this->reportingCurrencyService->normalizeEventRows($group, $target, false)['normalized_total'] ?? 0));

        $markets = $currentRows
            ->groupBy(fn ($row) => (int) $row->platform_id)
            ->map(function (Collection $group, $platformId) use ($target, $unlockCounts, $eventCounts) {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($group, $target, false);
                $breakdown = [];
                foreach ($group as $row) {
                    $currency = strtoupper((string) $row->currency);
                    $breakdown[$currency] = ($breakdown[$currency] ?? 0.0) + (float) $row->amount;
                }

                $id = (int) $platformId;
                $events = $eventCounts[$id] ?? [];
                $paidUnlocks = (int) $group->sum('payments_count');
                $checkoutStarts = (int) ($unlockCounts[$id] ?? 0);

                return [
                    'platform_id' => $id,
                    'name' => (string) ($group->first()->platform_name ?: 'Unassigned market'),
                    'country' => (string) ($group->first()->platform_country ?: ''),
                    'currency_code' => (string) ($group->first()->platform_currency ?: ''),
                    'source_breakdown' => $breakdown,
                    'normalized_total' => $normalized['normalized_total'],
                    'normalized_currency' => $normalized['normalized_currency'],
                    'payments_count' => $paidUnlocks,
                    'checkout_starts' => $checkoutStarts,
                    'cta_clicks' => (int) ($events[ContactUnlockEvent::TYPE_CTA_CLICK] ?? 0),
                    'eligible_views' => (int) ($events[ContactUnlockEvent::TYPE_ELIGIBLE_VIEW] ?? 0),
                    'conversion_percent' => $checkoutStarts > 0 ? round(($paidUnlocks / $checkoutStarts) * 100, 1) : 0.0,
                ];
            })
            ->values();

        $nativeBasis = $this->nativeReportingBasis($markets);
        $valueFor = function (array $row) use ($nativeBasis): ?float {
            if ($row['normalized_total'] !== null) {
                return (float) $row['normalized_total'];
            }

            return $nativeBasis === null ? null : (float) array_sum($row['source_breakdown'] ?? []);
        };

        $total = (float) $markets->sum(fn (array $market) => $valueFor($market) ?? 0.0);

        return $markets
            ->map(function (array $market) use ($total, $valueFor, $priorByPlatform) {
                $value = $valueFor($market);
                $prior = (float) ($priorByPlatform[$market['platform_id']] ?? 0);
                $market['value'] = $value ?? 0.0;
                $market['fx_unresolved'] = $value === null;
                $market['share_percent'] = ($value !== null && $total > 0) ? round(($value / $total) * 100, 1) : 0.0;
                $market['prior_value'] = $prior;
                $market['delta_percent'] = $this->percentDelta($value, $prior);
                $market['average_order_value'] = ($value !== null && $market['payments_count'] > 0)
                    ? round($value / $market['payments_count'], 2)
                    : null;

                return $market;
            })
            ->sortByDesc(fn (array $market) => $market['value'])
            ->values()
            ->all();
    }

    private function unlockCountsByPlatform(int|array|null $platformIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = VisitorContactUnlock::query()
            ->whereBetween('created_at', [$from, $to])
            ->select('platform_id', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('platform_id');
        $this->applyPlatformScope($query, $platformIds, 'platform_id');

        return $query->get()
            ->mapWithKeys(fn ($row) => [(int) $row->platform_id => (int) $row->aggregate_count])
            ->all();
    }

    private function eventCountsByPlatform(int|array|null $platformIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = ContactUnlockEvent::query()
            ->whereBetween('occurred_at', [$from, $to])
            ->select('platform_id', 'event_type', DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('platform_id', 'event_type');
        $this->applyPlatformScope($query, $platformIds, 'platform_id');

        $counts = [];
        foreach ($query->get() as $row) {
            $counts[(int) $row->platform_id][(string) $row->event_type] = (int) $row->aggregate_count;
        }

        return $counts;
    }

    /**
     * Mixed native currencies can never be summed, so an unconvertible market is reported as
     * FX-unresolved rather than silently counted at face value.
     */
    private function nativeReportingBasis(Collection $markets): ?string
    {
        if ($markets->isEmpty() || $markets->contains(fn (array $market) => $market['normalized_total'] !== null)) {
            return null;
        }

        $currencies = [];
        foreach ($markets as $market) {
            foreach (array_keys($market['source_breakdown'] ?? []) as $currency) {
                $currencies[$currency] = true;
            }
        }

        return count($currencies) === 1 ? (string) array_key_first($currencies) : null;
    }

    private function window(string $range, ?string $timezone, ?string $fromDate, ?string $toDate): array
    {
        $tz = $timezone ?: config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($tz);

        if ($range === 'custom' && $fromDate && $toDate) {
            return [
                CarbonImmutable::parse($fromDate, $tz)->startOfDay()->utc(),
                CarbonImmutable::parse($toDate, $tz)->endOfDay()->utc(),
            ];
        }

        $from = match ($range) {
            '7d' => $now->subDays(6)->startOfDay(),
            '30d' => $now->subDays(29)->startOfDay(),
            default => $now->startOfDay(),
        };

        return [$from->utc(), $now->utc()];
    }

    private function resolveBucket(string $bucket, int $days): string
    {
        $normalized = strtolower(trim($bucket));
        if (in_array($normalized, ['day', 'week', 'month'], true)) {
            return $normalized;
        }

        return match (true) {
            $days <= 45 => 'day',
            $days <= 240 => 'week',
            default => 'month',
        };
    }

    private function bucketStart(CarbonImmutable $date, string $bucket): CarbonImmutable
    {
        return match ($bucket) {
            'month' => $date->startOfMonth(),
            'week' => $date->startOfWeek(),
            default => $date->startOfDay(),
        };
    }

    private function bucketLabel(CarbonImmutable $date, string $bucket): string
    {
        return match ($bucket) {
            'month' => $date->format('Y-m'),
            'week' => $date->format('Y-\WW'),
            default => $date->toDateString(),
        };
    }

    private function applyPlatformScope($query, int|array|null $platformIds, string $column): void
    {
        if (is_int($platformIds)) {
            $query->where($column, $platformIds);

            return;
        }

        if (is_array($platformIds)) {
            if ($platformIds === []) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn($column, $platformIds);
        }
    }

    private function eventExpression(): string
    {
        return 'COALESCE(payments.completed_at, payments.updated_at, payments.created_at)';
    }

    private function dateExpression(): string
    {
        $inner = $this->eventExpression();

        return DB::connection()->getDriverName() === 'sqlite'
            ? "date({$inner})"
            : "DATE({$inner})";
    }

    private function percentDelta(?float $current, ?float $prior): ?float
    {
        if ($current === null || $prior === null || (float) $prior == 0.0) {
            return null;
        }

        return round((((float) $current - (float) $prior) / abs((float) $prior)) * 100, 1);
    }
}
