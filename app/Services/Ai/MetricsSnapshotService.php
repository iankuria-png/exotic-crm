<?php

namespace App\Services\Ai;

use App\Models\ClientActiveSnapshot;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\RenewalService;
use App\Services\ReportingCurrencyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a scoped, currency-normalized snapshot of CRM facts for AI features
 * (briefings + insights). Reuses the same query semantics as the CEO dashboard
 * (Payment::reportableSuccessful()->excludingWalletTopups(), COALESCE date,
 * ReportingCurrencyService normalization) and RenewalService for renewal risk,
 * so AI figures match the dashboard rather than diverging into a parallel pipeline.
 *
 * Read-only: it never mutates state.
 */
class MetricsSnapshotService
{
    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService,
        private readonly RenewalService $renewalService,
    ) {}

    /**
     * @param  int[]|null  $platformIds  null = org-wide (all markets).
     */
    public function forScope(?array $platformIds, Carbon $from, Carbon $to): array
    {
        $platformIds = $this->normalizePlatformIds($platformIds);
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency(null);

        $from = $from->copy()->startOfDay();
        $to   = $to->copy()->endOfDay();
        $days = max(1, $from->diffInDays($to) + 1);

        $priorTo   = $from->copy()->subSecond();
        $priorFrom = $priorTo->copy()->subDays($days - 1)->startOfDay();

        $revenue      = $this->revenue($from, $to, $platformIds, $targetCurrency);
        $priorRevenue = $this->revenue($priorFrom, $priorTo, $platformIds, $targetCurrency);

        $renewals = $this->renewalService->buildSummary(
            $platformIds === null ? [] : ['platform_ids' => $platformIds]
        );

        return [
            'scope' => [
                'org_wide'       => $platformIds === null,
                'platform_ids'   => $platformIds ?? [],
                'platform_names' => $this->platformNames($platformIds),
            ],
            'window' => [
                'from'            => $from->toDateString(),
                'to'              => $to->toDateString(),
                'days'            => $days,
                'prior_from'      => $priorFrom->toDateString(),
                'prior_to'        => $priorTo->toDateString(),
                'target_currency' => $targetCurrency,
            ],
            'revenue' => [
                'normalized_total'    => $revenue['normalized_total'],
                'normalized_currency' => $revenue['normalized_currency'],
                'payments_count'      => $revenue['payments_count'],
                'source_breakdown'    => $revenue['source_breakdown'],
                'prior_normalized_total' => $priorRevenue['normalized_total'],
                'delta_percent'       => $this->percentDelta($revenue['normalized_total'], $priorRevenue['normalized_total']),
            ],
            'active_subscribers' => $this->activeSubscribers($to, $platformIds),
            'renewals' => [
                'risk'            => (int) ($renewals['risk'] ?? 0),
                'pending'         => (int) ($renewals['pending'] ?? 0),
                'expired_deals'   => (int) ($renewals['expired_deals'] ?? 0),
                'lapsed_deals'    => (int) ($renewals['lapsed_deals'] ?? 0),
                'active_deals'    => (int) ($renewals['active_deals'] ?? 0),
                'pipeline_value'  => (float) ($renewals['pipeline_value'] ?? 0),
            ],
            'top_markets' => $this->topMarkets($from, $to, $platformIds, $targetCurrency),
            'top_agents'  => $this->topAgents($from, $to, $platformIds, $targetCurrency),
        ];
    }

    /**
     * Prioritized "who to call" renewal list scoped to the given markets.
     * Used by the AUTHENTICATED briefing page (PII allowed) — never sourced from
     * the PII-free reporting views.
     *
     * @param  int[]|null  $platformIds
     */
    public function priorityCalls(?array $platformIds, int $days = 7, int $limit = 15): array
    {
        $platformIds = $this->normalizePlatformIds($platformIds);
        $now = Carbon::now();
        $until = $now->copy()->addDays($days)->endOfDay();

        return \App\Models\Deal::query()
            ->with(['client:id,name,phone_normalized,platform_id', 'platform:id,name,country'])
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->whereBetween('expires_at', [$now, $until])
            ->when($platformIds !== null, fn (Builder $q) => $q->whereIn('platform_id', $platformIds))
            ->orderBy('expires_at')
            ->limit($limit)
            ->get()
            ->map(fn ($deal) => [
                'deal_id'    => (int) $deal->id,
                'client_id'  => $deal->client_id ? (int) $deal->client_id : null,
                'client_name' => $deal->client?->name,
                'phone'      => $deal->client?->phone_normalized,
                'market'     => $deal->platform?->name,
                'amount'     => $deal->amount !== null ? (float) $deal->amount : null,
                'currency'   => $deal->currency,
                'expires_at' => optional($deal->expires_at)->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Dashboard-aligned market ranking used by Talk to Your Data for questions
     * such as "which country has the most revenue". This intentionally mirrors
     * the Top Performing Markets widget: reportable payments, wallet top-ups
     * excluded, payments.created_at windowing, and ReportingCurrencyService FX
     * normalization over grouped event rows.
     *
     * @param  int[]|null  $platformIds  null = org-wide, [] = no accessible markets
     */
    public function topMarketsForDashboardWindow(
        ?array $platformIds,
        Carbon $from,
        Carbon $to,
        ?string $targetCurrency = null,
        int $limit = 25
    ): array {
        if ($platformIds !== null) {
            $platformIds = collect($platformIds)
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->unique()
                ->values()
                ->all();

            if ($platformIds === []) {
                return [];
            }
        }

        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($targetCurrency);
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $rows = Payment::query()
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->whereBetween('payments.created_at', [$from, $to])
            ->when($platformIds !== null, fn (Builder $query) => $query->whereIn('payments.platform_id', $platformIds))
            ->select(DB::raw($this->dateExpression() . ' as event_date'))
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}') as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(payments.id) as payments_count')
            ->groupByRaw($this->dateExpression())
            ->groupBy('payments.platform_id')
            ->groupBy('platforms.country')
            ->groupBy('platforms.name')
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}')")
            ->get();

        $ranked = $rows
            ->groupBy(fn ($row) => (int) $row->platform_id)
            ->map(function (Collection $group) use ($targetCurrency) {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($group, $targetCurrency, false);
                $breakdown = $group
                    ->groupBy(fn ($row) => strtoupper((string) $row->currency))
                    ->map(fn (Collection $currencyRows) => round((float) $currencyRows->sum(fn ($row) => (float) $row->amount), 2))
                    ->sortKeys()
                    ->all();

                return [
                    'platform_id' => (int) $group->first()->platform_id,
                    'name' => (string) ($group->first()->platform_name ?: 'Unassigned market'),
                    'country' => (string) ($group->first()->platform_country ?: ''),
                    'current_revenue_breakdown' => $breakdown,
                    'current_revenue_normalized' => $normalized['normalized_total'],
                    'current_revenue_normalized_display' => $normalized['normalized_display'],
                    'current_revenue_normalization_meta' => $normalized['normalization_meta'],
                    'normalized_currency' => $normalized['normalized_currency'] ?? $targetCurrency,
                    'payments_count' => (int) $group->sum(fn ($row) => (int) $row->payments_count),
                ];
            })
            ->sortByDesc(fn (array $row) => (float) ($row['current_revenue_normalized'] ?? array_sum($row['current_revenue_breakdown'] ?? [])))
            ->values();

        $total = (float) $ranked->sum(fn (array $row) => (float) ($row['current_revenue_normalized'] ?? 0));

        return $ranked
            ->take(max(1, $limit))
            ->map(function (array $row, int $index) use ($total) {
                $value = (float) ($row['current_revenue_normalized'] ?? 0);
                $row['rank'] = $index + 1;
                $row['share_percent'] = $total > 0 ? round(($value / $total) * 100, 1) : null;

                return $row;
            })
            ->all();
    }

    private function revenue(Carbon $from, Carbon $to, ?array $platformIds, string $targetCurrency): array
    {
        $query = $this->basePayments($from, $to, $platformIds);
        $normalized = $this->reportingCurrencyService->normalizePaymentQuery(clone $query, $targetCurrency, false);

        return [
            'normalized_total'    => (float) ($normalized['normalized_total'] ?? 0),
            'normalized_currency' => $normalized['normalized_currency'] ?? $targetCurrency,
            'source_breakdown'    => $normalized['source_breakdown'] ?? [],
            'payments_count'      => (int) (clone $query)->count(),
        ];
    }

    private function basePayments(Carbon $from, Carbon $to, ?array $platformIds): Builder
    {
        return Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) >= ?', [$from->toDateTimeString()])
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) <= ?', [$to->toDateTimeString()])
            ->when($platformIds !== null, fn (Builder $query) => $query->whereIn('payments.platform_id', $platformIds));
    }

    private function activeSubscribers(Carbon $asOf, ?array $platformIds): array
    {
        $rows = ClientActiveSnapshot::query()
            ->whereDate('date', '<=', $asOf->toDateString())
            ->when($platformIds !== null, fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->orderBy('platform_id')
            ->orderByDesc('date')
            ->get()
            ->unique('platform_id')
            ->values();

        return [
            'count' => (int) $rows->sum('count'),
            'as_of' => optional($rows->pluck('date')->filter()->sort()->last())?->toDateString(),
        ];
    }

    private function topMarkets(Carbon $from, Carbon $to, ?array $platformIds, string $targetCurrency, int $limit = 5): array
    {
        $rows = $this->basePayments($from, $to, $platformIds)
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->selectRaw('payments.platform_id')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}') as currency")
            ->selectRaw($this->dateExpression() . ' as event_date')
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(*) as payments_count')
            ->groupBy('payments.platform_id', 'platforms.name', 'platforms.country')
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}')")
            ->groupByRaw($this->dateExpression())
            ->get();

        return $rows
            ->groupBy('platform_id')
            ->map(function (Collection $group) use ($targetCurrency) {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($group, $targetCurrency, false);

                return [
                    'platform_id'         => (int) $group->first()->platform_id,
                    'name'                => (string) ($group->first()->platform_name ?: 'Unassigned market'),
                    'country'             => (string) ($group->first()->platform_country ?: ''),
                    'normalized_total'    => (float) ($normalized['normalized_total'] ?? 0),
                    'normalized_currency' => $normalized['normalized_currency'] ?? $targetCurrency,
                    'payments_count'      => (int) $group->sum('payments_count'),
                ];
            })
            ->sortByDesc('normalized_total')
            ->take($limit)
            ->values()
            ->all();
    }

    private function topAgents(Carbon $from, Carbon $to, ?array $platformIds, string $targetCurrency, int $limit = 5): array
    {
        $rows = $this->basePayments($from, $to, $platformIds)
            ->join('deals', 'deals.id', '=', 'payments.deal_id')
            ->join('users', 'users.id', '=', 'deals.assigned_to')
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->whereNotNull('deals.assigned_to')
            ->selectRaw('deals.assigned_to as agent_id')
            ->selectRaw('users.name as agent_name')
            ->selectRaw('users.role as agent_role')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}') as currency")
            ->selectRaw($this->dateExpression() . ' as event_date')
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(*) as payments_count')
            ->groupBy('deals.assigned_to', 'users.name', 'users.role')
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}')")
            ->groupByRaw($this->dateExpression())
            ->get();

        return $rows
            ->groupBy('agent_id')
            ->map(function (Collection $group) use ($targetCurrency) {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($group, $targetCurrency, false);

                return [
                    'agent_id'            => (int) $group->first()->agent_id,
                    'name'                => (string) $group->first()->agent_name,
                    'role'                => (string) $group->first()->agent_role,
                    'normalized_total'    => (float) ($normalized['normalized_total'] ?? 0),
                    'normalized_currency' => $normalized['normalized_currency'] ?? $targetCurrency,
                    'payments_count'      => (int) $group->sum('payments_count'),
                ];
            })
            ->sortByDesc('normalized_total')
            ->take($limit)
            ->values()
            ->all();
    }

    private function platformNames(?array $platformIds): array
    {
        if ($platformIds === null) {
            return [];
        }

        return Platform::query()
            ->whereIn('id', $platformIds)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /** @return int[]|null */
    private function normalizePlatformIds(?array $platformIds): ?array
    {
        if ($platformIds === null) {
            return null;
        }

        $ids = collect($platformIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        return $ids === [] ? null : $ids;
    }

    private function percentDelta(?float $current, ?float $prior): ?float
    {
        if ($current === null || $prior === null || (float) $prior == 0.0) {
            return null;
        }

        return round((((float) $current - (float) $prior) / abs((float) $prior)) * 100, 1);
    }

    private function dateExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'date(COALESCE(payments.completed_at, payments.created_at))'
            : 'DATE(COALESCE(payments.completed_at, payments.created_at))';
    }

}
