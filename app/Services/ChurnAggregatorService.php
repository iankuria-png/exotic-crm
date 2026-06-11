<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Support\CrmClientChurnReason;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ChurnAggregatorService
{
    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService,
    ) {}

    /**
     * Compute the full churn summary for a date range + optional platform scope.
     *
     * @param  array<int>  $platformIds  Empty = all accessible platforms
     */
    public function summary(Carbon $from, Carbon $to, array $platformIds = []): array
    {
        $daily = $this->dailySeries($from, $to, $platformIds);
        $totals = $this->totals($daily);
        $durations = $this->durationsByMarket($from, $to, $platformIds);
        $reasons = $this->reasonBreakdown($from, $to, $platformIds);
        $tierBreakdown = $this->tierBreakdown($from, $to, $platformIds);
        $dayCount = $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $previousTo = $from->copy()->subDay()->endOfDay();
        $previousFrom = $previousTo->copy()->subDays($dayCount - 1)->startOfDay();
        $previousTotals = $this->totals($this->dailySeries($previousFrom, $previousTo, $platformIds, false));

        $health = $this->healthLabel($totals);

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'daily' => $daily,
            'totals' => $totals,
            'previous_range' => [
                'from' => $previousFrom->toDateString(),
                'to' => $previousTo->toDateString(),
            ],
            'comparison' => $this->comparison($totals, $previousTotals),
            'health' => $health,
            'averages' => $this->weightedAverages($durations),
            'revenue_at_risk' => $this->revenueAtRiskSummary($daily),
            'durations_by_market' => $durations,
            'reason_breakdown' => $reasons,
            'tier_breakdown' => $tierBreakdown,
        ];
    }

    /**
     * Build a daily time series of signups, activations, and churn.
     */
    public function dailySeries(
        Carbon $from,
        Carbon $to,
        array $platformIds = [],
        bool $includeRevenueRisk = true,
    ): array {
        // Signups per day
        $signupsQuery = Client::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as cnt')
            ->whereBetween('created_at', [$from->startOfDay()->copy(), $to->endOfDay()->copy()])
            ->groupBy(DB::raw('DATE(created_at)'));
        if (! empty($platformIds)) {
            $signupsQuery->whereIn('platform_id', $platformIds);
        }
        $signups = $signupsQuery->pluck('cnt', 'date');

        // Activations per day (first_activated_at)
        $activationsQuery = Client::query()
            ->selectRaw('DATE(first_activated_at) as date, COUNT(*) as cnt')
            ->whereNotNull('first_activated_at')
            ->whereBetween('first_activated_at', [$from->startOfDay()->copy(), $to->endOfDay()->copy()])
            ->groupBy(DB::raw('DATE(first_activated_at)'));
        if (! empty($platformIds)) {
            $activationsQuery->whereIn('platform_id', $platformIds);
        }
        $activations = $activationsQuery->pluck('cnt', 'date');

        // Churn per day
        $churnQuery = Client::query()
            ->selectRaw('DATE(churned_at) as date, COUNT(*) as cnt')
            ->whereNotNull('churned_at')
            ->whereBetween('churned_at', [$from->startOfDay()->copy(), $to->endOfDay()->copy()])
            ->groupBy(DB::raw('DATE(churned_at)'));
        if (! empty($platformIds)) {
            $churnQuery->whereIn('platform_id', $platformIds);
        }
        $churn = $churnQuery->pluck('cnt', 'date');
        $tickets = $includeRevenueRisk
            ? $this->dailyAverageTickets($from, $to, $platformIds)
            : [];

        // Fill every day in the range (including days with 0s)
        $period = CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to->copy()->endOfDay());
        $series = [];
        foreach ($period as $day) {
            $dateStr = $day->toDateString();
            $series[] = [
                'date' => $dateStr,
                'signups' => (int) ($signups[$dateStr] ?? 0),
                'activations' => (int) ($activations[$dateStr] ?? 0),
                'churn' => (int) ($churn[$dateStr] ?? 0),
                'average_ticket_usd' => $tickets[$dateStr]['average_ticket_usd'] ?? null,
                'successful_payments' => (int) ($tickets[$dateStr]['payments_count'] ?? 0),
                'estimated_revenue_at_risk_usd' => isset($tickets[$dateStr]['average_ticket_usd'])
                    ? round((float) $tickets[$dateStr]['average_ticket_usd'] * (int) ($churn[$dateStr] ?? 0), 2)
                    : null,
            ];
        }

        return $series;
    }

    /**
     * Aggregate daily series into range totals.
     */
    public function totals(array $daily): array
    {
        $signups = array_sum(array_column($daily, 'signups'));
        $activations = array_sum(array_column($daily, 'activations'));
        $churn = array_sum(array_column($daily, 'churn'));

        return [
            'signups' => $signups,
            'activations' => $activations,
            'churn' => $churn,
            'net' => $signups - $churn,
        ];
    }

    /**
     * Per-market average paid lifetime and total relationship duration.
     */
    public function durationsByMarket(Carbon $from, Carbon $to, array $platformIds = []): array
    {
        $fromDate = $from->copy()->startOfDay()->toDateTimeString();
        $toDate = $to->copy()->endOfDay()->toDateTimeString();
        $paidLifetimeExpression = $this->dayDifferenceExpression('clients.first_activated_at', 'clients.churned_at');
        $relationshipExpression = $this->dayDifferenceExpression('clients.created_at', 'clients.churned_at');

        $query = Client::query()
            ->join('platforms', 'clients.platform_id', '=', 'platforms.id')
            ->select([
                'clients.platform_id',
                'platforms.name as platform_name',
            ])
            ->selectRaw(
                'SUM(CASE WHEN clients.churned_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as churn_count',
                [$fromDate, $toDate],
            )
            ->selectRaw(
                "AVG(CASE WHEN clients.churned_at BETWEEN ? AND ? AND clients.first_activated_at IS NOT NULL THEN {$paidLifetimeExpression} END) as avg_paid_lifetime_days",
                [$fromDate, $toDate],
            )
            ->selectRaw(
                "AVG(CASE WHEN clients.churned_at BETWEEN ? AND ? THEN {$relationshipExpression} END) as avg_total_relationship_days",
                [$fromDate, $toDate],
            )
            ->selectRaw(
                'SUM(CASE WHEN clients.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as signup_count',
                [$fromDate, $toDate],
            )
            ->selectRaw(
                'SUM(CASE WHEN clients.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) - SUM(CASE WHEN clients.churned_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as net_delta',
                [$fromDate, $toDate, $fromDate, $toDate],
            )
            ->where(function ($scope) use ($fromDate, $toDate) {
                $scope->whereBetween('clients.created_at', [$fromDate, $toDate])
                    ->orWhereBetween('clients.churned_at', [$fromDate, $toDate]);
            })
            ->groupBy('clients.platform_id', 'platforms.name')
            ->orderBy('churn_count', 'desc');

        if (! empty($platformIds)) {
            $query->whereIn('clients.platform_id', $platformIds);
        }

        return $query->get()->map(function ($row) {
            return [
                'platform_id' => (int) $row->platform_id,
                'name' => $row->platform_name,
                'churn_count' => (int) $row->churn_count,
                'signup_count' => (int) $row->signup_count,
                'avg_paid_lifetime_days' => $row->avg_paid_lifetime_days !== null
                    ? round((float) $row->avg_paid_lifetime_days, 1)
                    : null,
                'avg_total_relationship_days' => $row->avg_total_relationship_days !== null
                    ? round((float) $row->avg_total_relationship_days, 1)
                    : null,
                'net_delta' => (int) $row->net_delta,
            ];
        })->values()->all();
    }

    /**
     * Churn reason breakdown for the range.
     */
    public function reasonBreakdown(Carbon $from, Carbon $to, array $platformIds = []): array
    {
        $query = Client::query()
            ->selectRaw('churn_reason_code, churn_source, COUNT(*) as cnt')
            ->whereNotNull('churned_at')
            ->whereBetween('churned_at', [$from->startOfDay()->copy(), $to->endOfDay()->copy()])
            ->whereNotNull('churn_reason_code')
            ->groupBy('churn_reason_code', 'churn_source')
            ->orderBy('cnt', 'desc');

        if (! empty($platformIds)) {
            $query->whereIn('platform_id', $platformIds);
        }

        $rows = $query->get();

        // Group by reason_code, aggregate sources
        $grouped = [];
        foreach ($rows as $row) {
            $code = $row->churn_reason_code;
            $source = $row->churn_source;
            $cnt = (int) $row->cnt;

            if (! isset($grouped[$code])) {
                $grouped[$code] = [
                    'code' => $code,
                    'label' => CrmClientChurnReason::label($code),
                    'count' => 0,
                    'by_source' => [],
                ];
            }

            $grouped[$code]['count'] += $cnt;
            $grouped[$code]['by_source'][$source] = ($grouped[$code]['by_source'][$source] ?? 0) + $cnt;
        }

        usort($grouped, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_values($grouped);
    }

    public function tierBreakdown(Carbon $from, Carbon $to, array $platformIds = []): array
    {
        $clientsQuery = Client::query()
            ->whereNotNull('churned_at')
            ->whereBetween('churned_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);

        if (! empty($platformIds)) {
            $clientsQuery->whereIn('platform_id', $platformIds);
        }

        $clients = $clientsQuery->get(['id', 'churned_at']);
        if ($clients->isEmpty()) {
            return [];
        }

        $churnedAtByClient = $clients->pluck('churned_at', 'id');
        $deals = Deal::query()
            ->with('product:id,name,display_name,slug,tier')
            ->whereIn('client_id', $clients->pluck('id'))
            ->where(function (Builder $query) {
                $query->whereNotNull('activated_at')
                    ->orWhereIn('status', ClientFunnelService::PAID_DEAL_STATUSES);
            })
            ->orderByRaw('COALESCE(activated_at, created_at) DESC')
            ->orderByDesc('id')
            ->get()
            ->filter(function (Deal $deal) use ($churnedAtByClient): bool {
                $churnedAt = $churnedAtByClient->get($deal->client_id);
                $startedAt = $deal->activated_at ?? $deal->created_at;

                return $churnedAt !== null && $startedAt !== null && $startedAt->lte($churnedAt);
            })
            ->unique('client_id');

        $unknownCount = $clients->count() - $deals->count();
        $rows = $deals->map(function (Deal $deal) use ($churnedAtByClient): array {
            $presentation = Client::planPresentationFromPackageValues(
                $deal->product?->tier,
                $deal->product?->display_name ?: $deal->product?->name ?: $deal->plan_type,
                $deal->product?->slug,
            ) ?? ['key' => 'unknown', 'label' => 'Unknown'];
            $churnedAt = Carbon::parse($churnedAtByClient->get($deal->client_id));
            $startedAt = Carbon::parse($deal->activated_at ?? $deal->created_at);

            return [
                'key' => $presentation['key'],
                'label' => $presentation['label'],
                'days_on_last_tier' => max(0, $startedAt->diffInDays($churnedAt)),
            ];
        });

        if ($unknownCount > 0) {
            for ($index = 0; $index < $unknownCount; $index++) {
                $rows->push([
                    'key' => 'unknown',
                    'label' => 'Unknown',
                    'days_on_last_tier' => null,
                ]);
            }
        }

        $total = $rows->count();

        return $rows
            ->groupBy('key')
            ->map(function ($tierRows): array {
                $knownTenures = $tierRows->pluck('days_on_last_tier')->filter(fn ($value) => $value !== null);
                $earlyCount = $knownTenures->filter(fn ($days) => $days <= 30)->count();

                return [
                    'key' => $tierRows->first()['key'],
                    'label' => $tierRows->first()['label'],
                    'churn_count' => $tierRows->count(),
                    'avg_days_on_last_tier' => $knownTenures->isNotEmpty()
                        ? round((float) $knownTenures->avg(), 1)
                        : null,
                    'early_churn_count' => $earlyCount,
                    'early_churn_percent' => $knownTenures->isNotEmpty()
                        ? round(($earlyCount / $knownTenures->count()) * 100, 1)
                        : null,
                ];
            })
            ->map(function (array $row) use ($total): array {
                $row['share_of_churn_percent'] = $total > 0
                    ? round(($row['churn_count'] / $total) * 100, 1)
                    : 0.0;

                return $row;
            })
            ->sortByDesc('churn_count')
            ->values()
            ->all();
    }

    private function dailyAverageTickets(Carbon $from, Carbon $to, array $platformIds): array
    {
        $dateExpression = DB::connection()->getDriverName() === 'sqlite'
            ? 'date(COALESCE(payments.completed_at, payments.created_at))'
            : 'DATE(COALESCE(payments.completed_at, payments.created_at))';

        $rows = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) >= ?', [$from->copy()->startOfDay()->toDateTimeString()])
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) <= ?', [$to->copy()->endOfDay()->toDateTimeString()])
            ->when(! empty($platformIds), fn (Builder $query) => $query->whereIn('payments.platform_id', $platformIds))
            ->selectRaw("{$dateExpression} as event_date")
            ->selectRaw('payments.platform_id')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, 'USD') as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(*) as payments_count')
            ->groupByRaw($dateExpression)
            ->groupBy('payments.platform_id', 'platforms.name', 'platforms.country')
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, 'USD')")
            ->get();

        return $rows
            ->groupBy('event_date')
            ->map(function ($dateRows, string $date): array {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($dateRows, 'USD', false);
                $paymentsCount = (int) $dateRows->sum('payments_count');
                $normalizedTotal = $normalized['normalized_total'];

                return [
                    'average_ticket_usd' => $normalizedTotal !== null && $paymentsCount > 0
                        ? round((float) $normalizedTotal / $paymentsCount, 2)
                        : null,
                    'payments_count' => $paymentsCount,
                    'normalization_partial' => (bool) data_get($normalized, 'normalization_meta.partial', false),
                    'date' => $date,
                ];
            })
            ->all();
    }

    private function revenueAtRiskSummary(array $daily): array
    {
        $coveredRows = collect($daily)->filter(
            fn (array $row) => $row['churn'] > 0 && $row['estimated_revenue_at_risk_usd'] !== null
        );
        $churnWithTicket = (int) $coveredRows->sum('churn');
        $totalChurn = array_sum(array_column($daily, 'churn'));

        return [
            'currency' => 'USD',
            'estimated_total' => round((float) $coveredRows->sum('estimated_revenue_at_risk_usd'), 2),
            'weighted_average_ticket' => $churnWithTicket > 0
                ? round((float) $coveredRows->sum('estimated_revenue_at_risk_usd') / $churnWithTicket, 2)
                : null,
            'covered_churn_count' => $churnWithTicket,
            'total_churn_count' => $totalChurn,
            'coverage_percent' => $totalChurn > 0
                ? round(($churnWithTicket / $totalChurn) * 100, 1)
                : 100.0,
            'method' => 'daily_average_ticket_times_churn',
        ];
    }

    private function comparison(array $totals, array $previousTotals): array
    {
        $comparison = [];

        foreach (['signups', 'activations', 'churn', 'net'] as $key) {
            $current = (int) ($totals[$key] ?? 0);
            $previous = (int) ($previousTotals[$key] ?? 0);
            $comparison[$key] = [
                'current' => $current,
                'previous' => $previous,
                'delta' => $current - $previous,
                'percent' => $previous !== 0
                    ? round((($current - $previous) / abs($previous)) * 100, 1)
                    : null,
            ];
        }

        return $comparison;
    }

    private function weightedAverages(array $durations): array
    {
        $churnCount = array_sum(array_column($durations, 'churn_count'));

        if ($churnCount === 0) {
            return [
                'paid_lifetime_days' => null,
                'total_relationship_days' => null,
            ];
        }

        $paidWeight = 0;
        $paidTotal = 0.0;
        $relationshipTotal = 0.0;

        foreach ($durations as $duration) {
            $count = (int) $duration['churn_count'];
            $relationshipTotal += ((float) ($duration['avg_total_relationship_days'] ?? 0)) * $count;

            if ($duration['avg_paid_lifetime_days'] !== null) {
                $paidTotal += ((float) $duration['avg_paid_lifetime_days']) * $count;
                $paidWeight += $count;
            }
        }

        return [
            'paid_lifetime_days' => $paidWeight > 0 ? round($paidTotal / $paidWeight, 1) : null,
            'total_relationship_days' => round($relationshipTotal / $churnCount, 1),
        ];
    }

    private function dayDifferenceExpression(string $startColumn, string $endColumn): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "julianday({$endColumn}) - julianday({$startColumn})";
        }

        return "TIMESTAMPDIFF(DAY, {$startColumn}, {$endColumn})";
    }

    /**
     * Determine health label from net/signups ratio.
     */
    private function healthLabel(array $totals): string
    {
        $signups = $totals['signups'];
        $churn = $totals['churn'];

        if ($signups === 0) {
            return $churn === 0 ? 'neutral' : 'critical';
        }

        $netRatio = ($signups - $churn) / $signups;

        if ($netRatio >= 0.5) {
            return 'healthy';
        }
        if ($netRatio >= 0.0) {
            return 'watch';
        }

        return 'critical';
    }
}
