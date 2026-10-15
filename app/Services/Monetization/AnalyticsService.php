<?php

namespace App\Services\Monetization;

use App\Models\ClientMonetizationPass;
use App\Models\Payment;
use App\Models\VisitorContentPurchase;
use App\Services\ReportingCurrencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Monetize performance trend: buyer content spend, creator earnings, selling-pass revenue and
 * checkout completion, bucketed over a window with an equal-length prior window.
 *
 * Money follows the Overview cards exactly (active purchases and all passes by created_at), so the
 * chart reconciles with them. Pass revenue is Exotic income and stays its own series; it is never
 * added to buyer spend. Flat mode converts with cached FX only; Native reports a single currency
 * as-is and falls back to Flat when the scope mixes currencies.
 */
class AnalyticsService
{
    private const MONEY = ['gross', 'creator_credit', 'pass_revenue'];

    public function __construct(private readonly ReportingCurrencyService $reportingCurrency) {}

    public function analytics(
        ?array $platformIds,
        ?int $clientId,
        bool $includeSandbox,
        ?string $fromDate,
        ?string $toDate,
        string $currencyMode,
        ?string $reportingCurrency,
        string $bucket = 'auto'
    ): array {
        $allTime = ! $fromDate;
        $to = $toDate ? CarbonImmutable::parse($toDate)->startOfDay() : CarbonImmutable::today();
        $from = $fromDate
            ? CarbonImmutable::parse($fromDate)->startOfDay()
            : ($this->earliestDate($platformIds, $clientId, $includeSandbox) ?? $to->subDays(29));
        if ($from->gt($to)) {
            $from = $to;
        }
        $days = (int) $from->diffInDays($to) + 1;
        $priorTo = $from->subDay();
        $priorFrom = $priorTo->subDays($days - 1);
        $resolvedBucket = $this->resolveBucket($bucket, $days);

        $current = $this->rows($platformIds, $clientId, $includeSandbox, $from, $to);
        $prior = $allTime ? collect() : $this->rows($platformIds, $clientId, $includeSandbox, $priorFrom, $priorTo);

        $currencies = $current->merge($prior)->pluck('currency')->map(fn ($c) => strtoupper((string) $c))->unique()->values();
        $mode = $this->reportingCurrency->resolveMode($currencyMode, true);
        $native = $mode === ReportingCurrencyService::MODE_NATIVE && $currencies->count() <= 1;
        $target = $native
            ? (string) ($currencies->first() ?: $this->reportingCurrency->resolveTargetCurrency($reportingCurrency))
            : $this->reportingCurrency->resolveTargetCurrency($reportingCurrency);

        $currentPoints = $this->bucketed($current, $from, $to, $resolvedBucket, $target, $native);
        $priorPoints = array_values($this->bucketed($prior, $priorFrom, $priorTo, $resolvedBucket, $target, $native));
        $totals = $this->aggregate($current, $target, $native);
        $priorTotals = $allTime ? null : $this->aggregate($prior, $target, $native);

        return [
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'prior_from' => $allTime ? null : $priorFrom->toDateString(),
                'prior_to' => $allTime ? null : $priorTo->toDateString(),
                'all_time' => $allTime,
                'days' => $days,
                'bucket' => $resolvedBucket,
                'currency_mode' => $native ? ReportingCurrencyService::MODE_NATIVE : ReportingCurrencyService::MODE_FLAT,
                'currency' => $target,
                'native_fallback' => $mode === ReportingCurrencyService::MODE_NATIVE && ! $native,
                'source_currencies' => $currencies->all(),
            ],
            'totals' => $totals,
            'prior_totals' => $priorTotals,
            'deltas' => $priorTotals ? $this->deltas($totals, $priorTotals) : null,
            'points' => collect(array_values($currentPoints))->map(function (array $point, int $index) use ($priorPoints, $allTime) {
                $priorPoint = $allTime ? null : ($priorPoints[$index] ?? null);
                foreach (['gross', 'creator_credit', 'pass_revenue', 'purchases', 'attempts', 'paid_attempts', 'completion_rate', 'average_sale'] as $key) {
                    $point["prior_{$key}"] = $priorPoint[$key] ?? null;
                }
                $point['prior_label'] = $priorPoint['label'] ?? null;

                return $point;
            })->all(),
            'markets' => $this->markets($current, $prior, $target, $native),
        ];
    }

    /**
     * One row per (date, platform, currency, source). Purchases and passes are kept as separate
     * sources so their sums can never blend.
     */
    private function rows(?array $platformIds, ?int $clientId, bool $includeSandbox, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $successful = "'".implode("','", Payment::SUCCESSFUL_STATUSES)."'";
        $purchaseDate = $this->dateExpression('visitor_content_purchases.created_at');
        $purchases = VisitorContentPurchase::query()
            ->leftJoin('payments', 'payments.id', '=', 'visitor_content_purchases.payment_id')
            ->leftJoin('platforms', 'platforms.id', '=', 'visitor_content_purchases.platform_id')
            ->whereDate('visitor_content_purchases.created_at', '>=', $from->toDateString())
            ->whereDate('visitor_content_purchases.created_at', '<=', $to->toDateString())
            ->when(! $includeSandbox, fn ($q) => $q->where('visitor_content_purchases.is_sandbox', false))
            ->when($clientId, fn ($q) => $q->where('visitor_content_purchases.client_id', $clientId))
            ->selectRaw("{$purchaseDate} as event_date")
            ->selectRaw('visitor_content_purchases.platform_id as platform_id')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw('visitor_content_purchases.currency as currency')
            ->selectRaw("SUM(CASE WHEN visitor_content_purchases.status = 'active' THEN visitor_content_purchases.gross_amount ELSE 0 END) as gross")
            ->selectRaw("SUM(CASE WHEN visitor_content_purchases.status = 'active' THEN visitor_content_purchases.creator_credit_amount ELSE 0 END) as creator_credit")
            ->selectRaw("SUM(CASE WHEN visitor_content_purchases.status = 'active' THEN 1 ELSE 0 END) as purchases")
            ->selectRaw('COUNT(*) as attempts')
            ->selectRaw("SUM(CASE WHEN payments.status IN ({$successful}) THEN 1 ELSE 0 END) as paid_attempts")
            ->groupByRaw($purchaseDate)
            ->groupBy('visitor_content_purchases.platform_id', 'platforms.name', 'platforms.country', 'visitor_content_purchases.currency');
        $this->scope($purchases, $platformIds, 'visitor_content_purchases.platform_id');

        $passDate = $this->dateExpression('client_monetization_passes.created_at');
        $passes = ClientMonetizationPass::query()
            ->leftJoin('platforms', 'platforms.id', '=', 'client_monetization_passes.platform_id')
            ->whereDate('client_monetization_passes.created_at', '>=', $from->toDateString())
            ->whereDate('client_monetization_passes.created_at', '<=', $to->toDateString())
            ->when(! $includeSandbox, fn ($q) => $q->where('client_monetization_passes.is_sandbox', false))
            ->when($clientId, fn ($q) => $q->where('client_monetization_passes.client_id', $clientId))
            ->selectRaw("{$passDate} as event_date")
            ->selectRaw('client_monetization_passes.platform_id as platform_id')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw('client_monetization_passes.currency as currency')
            ->selectRaw('SUM(client_monetization_passes.paid_amount) as pass_revenue')
            ->groupByRaw($passDate)
            ->groupBy('client_monetization_passes.platform_id', 'platforms.name', 'platforms.country', 'client_monetization_passes.currency');
        $this->scope($passes, $platformIds, 'client_monetization_passes.platform_id');

        $shape = fn ($row, array $values) => [
            'event_date' => substr((string) $row->event_date, 0, 10),
            'platform_id' => (int) $row->platform_id,
            'platform_name' => $row->platform_name,
            'platform_country' => $row->platform_country,
            'currency' => strtoupper((string) $row->currency),
            ...$values,
        ];

        return $purchases->get()
            ->map(fn ($row) => $shape($row, [
                'gross' => (float) $row->gross,
                'creator_credit' => (float) $row->creator_credit,
                'pass_revenue' => 0.0,
                'purchases' => (int) $row->purchases,
                'attempts' => (int) $row->attempts,
                'paid_attempts' => (int) $row->paid_attempts,
            ]))
            ->merge($passes->get()->map(fn ($row) => $shape($row, [
                'gross' => 0.0,
                'creator_credit' => 0.0,
                'pass_revenue' => (float) $row->pass_revenue,
                'purchases' => 0,
                'attempts' => 0,
                'paid_attempts' => 0,
            ])))
            ->values();
    }

    private function aggregate(Collection $rows, string $target, bool $native): array
    {
        $result = ['unresolved_currencies' => [], 'fx_as_of' => null, 'fx_stale' => false];
        foreach (self::MONEY as $field) {
            $converted = $this->convert($rows, $field, $target, $native);
            $result[$field] = $converted['value'];
            $result['unresolved_currencies'] = array_values(array_unique([...$result['unresolved_currencies'], ...$converted['unresolved']]));
            $result['fx_as_of'] = max($result['fx_as_of'], $converted['as_of']);
            $result['fx_stale'] = $result['fx_stale'] || $converted['stale'];
        }
        $result['purchases'] = (int) $rows->sum('purchases');
        $result['attempts'] = (int) $rows->sum('attempts');
        $result['paid_attempts'] = (int) $rows->sum('paid_attempts');
        $result['completion_rate'] = $result['attempts'] > 0 ? round($result['paid_attempts'] / $result['attempts'] * 100, 1) : null;
        $result['average_sale'] = $result['purchases'] > 0 ? round($result['gross'] / $result['purchases'], 2) : null;

        return $result;
    }

    /**
     * Converts one money field. A currency without a cached rate is excluded and named rather
     * than counted at face value or zeroing the whole bucket.
     */
    private function convert(Collection $rows, string $field, string $target, bool $native): array
    {
        $rows = $rows->filter(fn (array $row) => (float) $row[$field] != 0.0);
        if ($native || $rows->isEmpty()) {
            return ['value' => round((float) $rows->sum($field), 2), 'unresolved' => [], 'as_of' => null, 'stale' => false];
        }

        $normalized = $this->reportingCurrency->normalizeEventRows(
            $rows->map(fn (array $row) => [...$row, 'amount' => $row[$field]]),
            $target,
            false
        );
        $meta = $normalized['normalization_meta'] ?? [];
        $value = 0.0;
        foreach ($meta['rows'] ?? [] as $line) {
            $value += (float) ($line['normalized_amount'] ?? 0);
        }

        return [
            'value' => round($value, 2),
            'unresolved' => array_values($meta['missing_currencies'] ?? []),
            'as_of' => $meta['as_of'] ?? null,
            'stale' => (bool) ($meta['stale'] ?? false),
        ];
    }

    private function bucketed(Collection $rows, CarbonImmutable $from, CarbonImmutable $to, string $bucket, string $target, bool $native): array
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
            $label = $this->bucketLabel($this->bucketStart(CarbonImmutable::parse($row['event_date']), $bucket), $bucket);
            if (array_key_exists($label, $buckets)) {
                $buckets[$label]->push($row);
            }
        }

        $points = [];
        foreach ($buckets as $label => $bucketRows) {
            $totals = $this->aggregate($bucketRows, $target, $native);
            $points[$label] = [
                'label' => $label,
                'gross' => $totals['gross'],
                'creator_credit' => $totals['creator_credit'],
                'pass_revenue' => $totals['pass_revenue'],
                'purchases' => $totals['purchases'],
                'attempts' => $totals['attempts'],
                'paid_attempts' => $totals['paid_attempts'],
                'completion_rate' => $totals['completion_rate'],
                'average_sale' => $totals['average_sale'],
                'fx_unresolved' => $totals['unresolved_currencies'] !== [],
            ];
        }

        return $points;
    }

    private function markets(Collection $current, Collection $prior, string $target, bool $native): array
    {
        $priorByPlatform = $prior->groupBy('platform_id')
            ->map(fn (Collection $group) => $this->convert($group, 'gross', $target, $native)['value']);

        $markets = $current->groupBy('platform_id')->map(function (Collection $group, $platformId) use ($target, $native, $priorByPlatform) {
            $totals = $this->aggregate($group, $target, $native);
            $breakdown = [];
            foreach ($group as $row) {
                $breakdown[$row['currency']] = round(($breakdown[$row['currency']] ?? 0) + $row['gross'], 2);
            }
            $prior = $priorByPlatform[$platformId] ?? null;

            return [
                'platform_id' => (int) $platformId,
                'name' => (string) ($group->first()['platform_name'] ?: 'Unassigned market'),
                'country' => (string) ($group->first()['platform_country'] ?: ''),
                'native_gross' => $breakdown,
                'prior_gross' => $prior,
                'delta_percent' => $this->percentDelta($totals['gross'], $prior),
                'fx_unresolved' => $totals['unresolved_currencies'] !== [],
                ...collect($totals)->except(['unresolved_currencies', 'fx_as_of', 'fx_stale'])->all(),
            ];
        })->values();

        $total = (float) $markets->sum('gross');

        return $markets
            ->map(fn (array $market) => [...$market, 'share_percent' => $total > 0 ? round($market['gross'] / $total * 100, 1) : 0.0])
            ->sortByDesc(fn (array $market) => [$market['gross'], $market['pass_revenue'], $market['attempts']])
            ->values()
            ->all();
    }

    private function deltas(array $current, array $prior): array
    {
        $deltas = [];
        foreach (['gross', 'creator_credit', 'pass_revenue', 'purchases', 'average_sale'] as $key) {
            $deltas[$key] = $this->percentDelta($current[$key], $prior[$key]);
        }
        $deltas['completion_rate'] = $current['completion_rate'] !== null && $prior['completion_rate'] !== null
            ? round($current['completion_rate'] - $prior['completion_rate'], 1)
            : null;

        return $deltas;
    }

    private function earliestDate(?array $platformIds, ?int $clientId, bool $includeSandbox): ?CarbonImmutable
    {
        $earliest = collect([VisitorContentPurchase::query(), ClientMonetizationPass::query()])
            ->map(function ($query) use ($platformIds, $clientId, $includeSandbox) {
                $this->scope($query, $platformIds, 'platform_id');

                return $query->when(! $includeSandbox, fn ($q) => $q->where('is_sandbox', false))
                    ->when($clientId, fn ($q) => $q->where('client_id', $clientId))
                    ->min('created_at');
            })
            ->filter()
            ->min();

        return $earliest ? CarbonImmutable::parse($earliest)->startOfDay() : null;
    }

    private function scope($query, ?array $platformIds, string $column): void
    {
        if (is_array($platformIds)) {
            $platformIds === [] ? $query->whereRaw('1 = 0') : $query->whereIn($column, $platformIds);
        }
    }

    private function resolveBucket(string $bucket, int $days): string
    {
        $bucket = strtolower(trim($bucket));
        if (in_array($bucket, ['day', 'week', 'month'], true)) {
            return $bucket;
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
            'week' => $date->format('o-\WW'),
            default => $date->toDateString(),
        };
    }

    private function dateExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite' ? "date({$column})" : "DATE({$column})";
    }

    private function percentDelta(?float $current, ?float $prior): ?float
    {
        if ($current === null || $prior === null || $prior == 0.0) {
            return null;
        }

        return round(($current - $prior) / abs($prior) * 100, 1);
    }
}
