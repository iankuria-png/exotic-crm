<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Platform;
use App\Support\CrmClientChurnReason;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

class ChurnAggregatorService
{
    /**
     * Compute the full churn summary for a date range + optional platform scope.
     *
     * @param Carbon $from
     * @param Carbon $to
     * @param array<int> $platformIds  Empty = all accessible platforms
     * @return array
     */
    public function summary(Carbon $from, Carbon $to, array $platformIds = []): array
    {
        $daily = $this->dailySeries($from, $to, $platformIds);
        $totals = $this->totals($daily);
        $durations = $this->durationsByMarket($from, $to, $platformIds);
        $reasons = $this->reasonBreakdown($from, $to, $platformIds);

        $health = $this->healthLabel($totals);

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'daily' => $daily,
            'totals' => $totals,
            'health' => $health,
            'durations_by_market' => $durations,
            'reason_breakdown' => $reasons,
        ];
    }

    /**
     * Build a daily time series of signups, activations, and churn.
     */
    public function dailySeries(Carbon $from, Carbon $to, array $platformIds = []): array
    {
        // Signups per day
        $signupsQuery = Client::query()
            ->selectRaw('DATE(created_at) as date, COUNT(*) as cnt')
            ->whereBetween('created_at', [$from->startOfDay()->copy(), $to->endOfDay()->copy()])
            ->groupBy(DB::raw('DATE(created_at)'));
        if (!empty($platformIds)) {
            $signupsQuery->whereIn('platform_id', $platformIds);
        }
        $signups = $signupsQuery->pluck('cnt', 'date');

        // Activations per day (first_activated_at)
        $activationsQuery = Client::query()
            ->selectRaw('DATE(first_activated_at) as date, COUNT(*) as cnt')
            ->whereNotNull('first_activated_at')
            ->whereBetween('first_activated_at', [$from->startOfDay()->copy(), $to->endOfDay()->copy()])
            ->groupBy(DB::raw('DATE(first_activated_at)'));
        if (!empty($platformIds)) {
            $activationsQuery->whereIn('platform_id', $platformIds);
        }
        $activations = $activationsQuery->pluck('cnt', 'date');

        // Churn per day
        $churnQuery = Client::query()
            ->selectRaw('DATE(churned_at) as date, COUNT(*) as cnt')
            ->whereNotNull('churned_at')
            ->whereBetween('churned_at', [$from->startOfDay()->copy(), $to->endOfDay()->copy()])
            ->groupBy(DB::raw('DATE(churned_at)'));
        if (!empty($platformIds)) {
            $churnQuery->whereIn('platform_id', $platformIds);
        }
        $churn = $churnQuery->pluck('cnt', 'date');

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
        $query = Client::query()
            ->join('platforms', 'clients.platform_id', '=', 'platforms.id')
            ->select([
                'clients.platform_id',
                'platforms.name as platform_name',
                DB::raw('COUNT(*) as churn_count'),
                DB::raw('AVG(TIMESTAMPDIFF(DAY, clients.first_activated_at, clients.churned_at)) as avg_paid_lifetime_days'),
                DB::raw('AVG(TIMESTAMPDIFF(DAY, clients.created_at, clients.churned_at)) as avg_total_relationship_days'),
                // Net delta: signups - churn in range per market
                DB::raw('SUM(CASE WHEN clients.churned_at BETWEEN ? AND ? THEN -1 ELSE 0 END) + SUM(CASE WHEN clients.created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as net_delta'),
            ])
            ->addBinding([$from->toDateTimeString(), $to->toDateTimeString(), $from->toDateTimeString(), $to->toDateTimeString()], 'select')
            ->whereNotNull('clients.churned_at')
            ->whereBetween('clients.churned_at', [$from->startOfDay()->copy(), $to->endOfDay()->copy()])
            ->whereNotNull('clients.first_activated_at')
            ->groupBy('clients.platform_id', 'platforms.name')
            ->orderBy('churn_count', 'desc');

        if (!empty($platformIds)) {
            $query->whereIn('clients.platform_id', $platformIds);
        }

        return $query->get()->map(function ($row) {
            return [
                'platform_id' => (int) $row->platform_id,
                'name' => $row->platform_name,
                'churn_count' => (int) $row->churn_count,
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

        if (!empty($platformIds)) {
            $query->whereIn('platform_id', $platformIds);
        }

        $rows = $query->get();

        // Group by reason_code, aggregate sources
        $grouped = [];
        foreach ($rows as $row) {
            $code = $row->churn_reason_code;
            $source = $row->churn_source;
            $cnt = (int) $row->cnt;

            if (!isset($grouped[$code])) {
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
