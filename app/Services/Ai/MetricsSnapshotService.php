<?php

namespace App\Services\Ai;

use App\Models\AgentSession;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientActiveSnapshot;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\ChurnAggregatorService;
use App\Services\PaymentRecoveryMetricService;
use App\Services\RenewalService;
use App\Services\ReportingCurrencyService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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
        private readonly PaymentRecoveryMetricService $paymentRecoveryMetricService,
        private readonly ChurnAggregatorService $churnAggregatorService,
    ) {}

    /**
     * @param  int[]|null  $platformIds  null = org-wide (all markets).
     */
    public function forScope(?array $platformIds, Carbon $from, Carbon $to): array
    {
        $platformIds = $this->normalizePlatformIds($platformIds);
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency(null);

        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $days = max(1, $from->diffInDays($to) + 1);

        $priorTo = $from->copy()->subSecond();
        $priorFrom = $priorTo->copy()->subDays($days - 1)->startOfDay();

        $revenue = $this->revenue($from, $to, $platformIds, $targetCurrency);
        $priorRevenue = $this->revenue($priorFrom, $priorTo, $platformIds, $targetCurrency);

        $renewals = $this->renewalService->buildSummary(
            $platformIds === null ? [] : ['platform_ids' => $platformIds]
        );
        $period = $this->periodDescriptor($from, $to, $priorFrom, $priorTo);
        $activeSubscribers = $this->activeSubscribers($to, $platformIds);
        $priorActiveSubscribers = $this->activeSubscribers($priorTo, $platformIds);
        $marketMovement = $this->marketMovement($from, $to, $priorFrom, $priorTo, $platformIds, $targetCurrency);
        $customerMovement = $this->customerMovement($from, $to, $platformIds, $activeSubscribers, $priorActiveSubscribers);
        $paymentRecovery = $this->paymentRecovery($from, $to, $priorFrom, $priorTo, $platformIds, $targetCurrency);
        $teamExecution = $this->teamExecution($from, $to, $priorFrom, $priorTo, $platformIds, $targetCurrency);

        $snapshot = [
            'version' => 'executive_scorecard_v3',
            'scope' => [
                'org_wide' => $platformIds === null,
                'platform_ids' => $platformIds ?? [],
                'platform_names' => $this->platformNames($platformIds),
            ],
            'period' => $period,
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
                'prior_from' => $priorFrom->toDateString(),
                'prior_to' => $priorTo->toDateString(),
                'target_currency' => $targetCurrency,
            ],
            'revenue' => [
                'normalized_total' => $revenue['normalized_total'],
                'normalized_currency' => $revenue['normalized_currency'],
                'payments_count' => $revenue['payments_count'],
                'source_breakdown' => $revenue['source_breakdown'],
                'prior_normalized_total' => $priorRevenue['normalized_total'],
                'prior_payments_count' => $priorRevenue['payments_count'],
                'delta_percent' => $this->percentDelta($revenue['normalized_total'], $priorRevenue['normalized_total']),
                'delta_amount' => round($revenue['normalized_total'] - $priorRevenue['normalized_total'], 2),
                'average_daily' => round($revenue['normalized_total'] / $days, 2),
                'prior_average_daily' => round($priorRevenue['normalized_total'] / $days, 2),
                'average_daily_delta_percent' => $this->percentDelta(
                    round($revenue['normalized_total'] / $days, 2),
                    round($priorRevenue['normalized_total'] / $days, 2),
                ),
                'average_ticket' => $revenue['payments_count'] > 0
                    ? round($revenue['normalized_total'] / $revenue['payments_count'], 2)
                    : 0.0,
                'prior_average_ticket' => $priorRevenue['payments_count'] > 0
                    ? round($priorRevenue['normalized_total'] / $priorRevenue['payments_count'], 2)
                    : 0.0,
            ],
            'active_subscribers' => [
                ...$activeSubscribers,
                'prior_count' => (int) ($priorActiveSubscribers['count'] ?? 0),
                'prior_as_of' => $priorActiveSubscribers['as_of'] ?? null,
                'delta' => (int) ($activeSubscribers['count'] ?? 0) - (int) ($priorActiveSubscribers['count'] ?? 0),
                'delta_percent' => $this->percentDelta(
                    (float) ($activeSubscribers['count'] ?? 0),
                    (float) ($priorActiveSubscribers['count'] ?? 0),
                ),
            ],
            'renewals' => [
                'risk' => (int) ($renewals['risk'] ?? 0),
                'pending' => (int) ($renewals['pending'] ?? 0),
                'expired_deals' => (int) ($renewals['expired_deals'] ?? 0),
                'lapsed_deals' => (int) ($renewals['lapsed_deals'] ?? 0),
                'active_deals' => (int) ($renewals['active_deals'] ?? 0),
                'pipeline_value' => (float) ($renewals['pipeline_value'] ?? 0),
            ],
            'top_markets' => $this->topMarkets($from, $to, $platformIds, $targetCurrency),
            'top_agents' => $this->topAgents($from, $to, $platformIds, $targetCurrency),
            'market_movement' => $marketMovement,
            'customer_movement' => $customerMovement,
            'payment_recovery' => $paymentRecovery,
            'team_execution' => $teamExecution,
            'data_quality' => [
                'generated_at' => Carbon::now()->toIso8601String(),
                'freshness_label' => 'Generated '.Carbon::now()->timezone(config('app.timezone', 'Africa/Nairobi'))->format('d M Y H:i'),
                'confidence' => 'medium',
                'caveats' => array_values(array_filter([
                    'Revenue uses reportable successful payments with wallet top-ups excluded.',
                    'Team active hours are CRM session time and may include work outside a specific market scope.',
                    (($paymentRecovery['normalization_partial'] ?? false) ? 'Some payment recovery values could not be fully currency-normalized; native amounts are retained in the source breakdown.' : null),
                ])),
            ],
        ];

        $snapshot['scorecards'] = $this->scorecards(
            $revenue,
            $priorRevenue,
            $customerMovement,
            $paymentRecovery,
            $teamExecution,
            $activeSubscribers,
            $priorActiveSubscribers,
            $days,
        );
        $snapshot['executive_focus'] = $this->executiveFocus($snapshot);

        return $snapshot;
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
                'deal_id' => (int) $deal->id,
                'client_id' => $deal->client_id ? (int) $deal->client_id : null,
                'client_name' => $deal->client?->name,
                'phone' => $deal->client?->phone_normalized,
                'market' => $deal->platform?->name,
                'amount' => $deal->amount !== null ? (float) $deal->amount : null,
                'currency' => $deal->currency,
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
            ->select(DB::raw($this->dateExpression().' as event_date'))
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
            'normalized_total' => (float) ($normalized['normalized_total'] ?? 0),
            'normalized_currency' => $normalized['normalized_currency'] ?? $targetCurrency,
            'source_breakdown' => $normalized['source_breakdown'] ?? [],
            'payments_count' => (int) (clone $query)->count(),
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
            ->selectRaw($this->dateExpression().' as event_date')
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
                    'platform_id' => (int) $group->first()->platform_id,
                    'name' => (string) ($group->first()->platform_name ?: 'Unassigned market'),
                    'country' => (string) ($group->first()->platform_country ?: ''),
                    'normalized_total' => (float) ($normalized['normalized_total'] ?? 0),
                    'normalized_currency' => $normalized['normalized_currency'] ?? $targetCurrency,
                    'payments_count' => (int) $group->sum('payments_count'),
                ];
            })
            ->sortByDesc('normalized_total')
            ->take($limit)
            ->values()
            ->all();
    }

    private function marketMovement(
        Carbon $from,
        Carbon $to,
        Carbon $priorFrom,
        Carbon $priorTo,
        ?array $platformIds,
        string $targetCurrency
    ): array {
        $current = collect($this->topMarkets($from, $to, $platformIds, $targetCurrency, 100))
            ->keyBy('platform_id');
        $prior = collect($this->topMarkets($priorFrom, $priorTo, $platformIds, $targetCurrency, 100))
            ->keyBy('platform_id');
        $total = max(0.0, (float) $current->sum('normalized_total'));

        $rows = $current
            ->keys()
            ->merge($prior->keys())
            ->unique()
            ->map(function ($id) use ($current, $prior, $targetCurrency, $total) {
                $cur = $current->get($id, []);
                $pri = $prior->get($id, []);
                $currentValue = (float) ($cur['normalized_total'] ?? 0);
                $priorValue = (float) ($pri['normalized_total'] ?? 0);
                $delta = round($currentValue - $priorValue, 2);

                return [
                    'platform_id' => (int) $id,
                    'name' => (string) (($cur['name'] ?? null) ?: ($pri['name'] ?? 'Unknown market')),
                    'country' => (string) (($cur['country'] ?? null) ?: ($pri['country'] ?? '')),
                    'current' => $currentValue,
                    'prior' => $priorValue,
                    'delta' => $delta,
                    'delta_percent' => $this->percentDelta($currentValue, $priorValue),
                    'share_percent' => $total > 0 ? round(($currentValue / $total) * 100, 1) : 0.0,
                    'payments_count' => (int) ($cur['payments_count'] ?? 0),
                    'currency' => (string) (($cur['normalized_currency'] ?? null) ?: $targetCurrency),
                    'direction' => $this->direction($currentValue, $priorValue),
                ];
            })
            ->values();

        $topMarket = $rows->sortByDesc('current')->first();

        return [
            'growing' => $rows
                ->filter(fn (array $row) => (float) $row['delta'] > 0)
                ->sortByDesc('delta')
                ->take(5)
                ->values()
                ->all(),
            'declining' => $rows
                ->filter(fn (array $row) => (float) $row['delta'] < 0)
                ->sortBy('delta')
                ->take(5)
                ->values()
                ->all(),
            'largest' => $rows
                ->sortByDesc('current')
                ->take(5)
                ->values()
                ->all(),
            'concentration' => [
                'top_market' => $topMarket,
                'top_market_share_percent' => (float) ($topMarket['share_percent'] ?? 0),
            ],
        ];
    }

    private function customerMovement(
        Carbon $from,
        Carbon $to,
        ?array $platformIds,
        array $activeSubscribers,
        array $priorActiveSubscribers
    ): array {
        $ids = $platformIds ?? [];
        $movement = $this->churnAggregatorService->movement($from->copy(), $to->copy(), $ids, 'week');
        $churnSummary = $this->churnAggregatorService->summary($from->copy(), $to->copy(), $ids);
        $totals = (array) ($movement['totals'] ?? []);
        $comparison = (array) ($movement['comparison'] ?? []);
        $churnTotals = (array) ($churnSummary['totals'] ?? []);
        $churnComparison = (array) ($churnSummary['comparison'] ?? []);
        $revenueAtRisk = (array) ($churnSummary['revenue_at_risk'] ?? []);

        $expiredProfiles = Client::query()
            ->whereBetween('lifecycle_expired_at', [$from, $to])
            ->when($platformIds !== null, fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->count();

        $expiredDeals = Deal::query()
            ->whereBetween('expires_at', [$from, $to])
            ->when($platformIds !== null, fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->count();

        $renewalsDue = Deal::query()
            ->whereBetween('expires_at', [$from, $to])
            ->whereIn('status', ['active', 'expired', 'renewed'])
            ->when($platformIds !== null, fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->count();

        $renewalPayments = (clone $this->basePayments($from, $to, $platformIds))
            ->leftJoin('deals', 'deals.id', '=', 'payments.deal_id')
            ->where(function (Builder $query) {
                $query->where('payments.subscription_lifecycle', 'renewal')
                    ->orWhere('deals.subscription_lifecycle', 'renewal');
            })
            ->count();

        return [
            'created_profiles' => (int) ($totals['created_profiles'] ?? 0),
            'created_profiles_comparison' => $this->normalizeCountComparison($comparison['created_profiles'] ?? null),
            'new_paid_customers' => (int) ($totals['new_paid_activations'] ?? 0),
            'new_paid_customers_comparison' => $this->normalizeCountComparison($comparison['new_paid_activations'] ?? null),
            'renewed_profiles' => (int) ($totals['renewed_profiles'] ?? 0),
            'renewed_profiles_comparison' => $this->normalizeCountComparison($comparison['renewed_profiles'] ?? null),
            'reactivated_profiles' => (int) ($totals['reactivated_profiles'] ?? 0),
            'expired_profiles' => (int) $expiredProfiles,
            'inactive_profiles' => (int) ($totals['inactive_profiles'] ?? 0),
            'inactive_profiles_comparison' => $this->normalizeCountComparison($comparison['inactive_profiles'] ?? null),
            'churned_profiles' => (int) ($churnTotals['churn'] ?? $totals['inactive_profiles'] ?? 0),
            'churned_profiles_comparison' => $this->normalizeCountComparison($churnComparison['churn'] ?? $comparison['inactive_profiles'] ?? null),
            'lost_value_to_churn' => (float) ($revenueAtRisk['estimated_total'] ?? 0.0),
            'lost_value_to_churn_currency' => (string) ($revenueAtRisk['currency'] ?? 'USD'),
            'lost_value_to_churn_display' => $this->formatMoney(
                (float) ($revenueAtRisk['estimated_total'] ?? 0.0),
                (string) ($revenueAtRisk['currency'] ?? 'USD'),
            ),
            'lost_value_to_churn_coverage_percent' => (float) ($revenueAtRisk['coverage_percent'] ?? 100.0),
            'expired_deals' => (int) $expiredDeals,
            'renewals_due' => (int) $renewalsDue,
            'renewal_payments' => (int) $renewalPayments,
            'renewal_save_rate' => $renewalsDue > 0 ? round(($renewalPayments / $renewalsDue) * 100, 1) : null,
            'net_active_movement' => (int) ($totals['net_active_movement'] ?? 0),
            'net_active_movement_comparison' => $this->normalizeCountComparison($comparison['net_active_movement'] ?? null),
            'active_subscribers_snapshot' => [
                'current' => (int) ($activeSubscribers['count'] ?? 0),
                'prior' => (int) ($priorActiveSubscribers['count'] ?? 0),
                'delta' => (int) ($activeSubscribers['count'] ?? 0) - (int) ($priorActiveSubscribers['count'] ?? 0),
                'delta_percent' => $this->percentDelta(
                    (float) ($activeSubscribers['count'] ?? 0),
                    (float) ($priorActiveSubscribers['count'] ?? 0),
                ),
                'as_of' => $activeSubscribers['as_of'] ?? null,
                'prior_as_of' => $priorActiveSubscribers['as_of'] ?? null,
            ],
            'definition' => $movement['definition'] ?? [],
        ];
    }

    private function paymentRecovery(
        Carbon $from,
        Carbon $to,
        Carbon $priorFrom,
        Carbon $priorTo,
        ?array $platformIds,
        string $targetCurrency
    ): array {
        $current = $this->paymentRecoveryMetricService->compute($platformIds, $from, $to);
        $prior = $this->paymentRecoveryMetricService->compute($platformIds, $priorFrom, $priorTo);
        $failed = $this->normalizeRecoveryRows(
            (array) ($current['failed_amount_rows'] ?? []),
            (array) ($current['failed_amount_breakdown'] ?? []),
            $to,
            $targetCurrency,
        );
        $recovered = $this->normalizeRecoveryRows(
            (array) ($current['recovered_amount_rows'] ?? []),
            (array) ($current['recovered_amount_breakdown'] ?? []),
            $to,
            $targetCurrency,
        );
        $lost = $this->normalizeRecoveryRows(
            (array) ($current['lost_amount_rows'] ?? []),
            (array) ($current['lost_amount_breakdown'] ?? []),
            $to,
            $targetCurrency,
        );

        return [
            'failed_payments' => (int) ($current['failed_payments'] ?? 0),
            'recovered_payments' => (int) ($current['recovered_payments'] ?? 0),
            'lost_payments' => (int) ($current['lost_payments'] ?? 0),
            'payment_recovery_rate' => (float) ($current['payment_recovery_rate'] ?? 0.0),
            'prior_payment_recovery_rate' => (float) ($prior['payment_recovery_rate'] ?? 0.0),
            'payment_recovery_rate_delta' => round(
                (float) ($current['payment_recovery_rate'] ?? 0.0) - (float) ($prior['payment_recovery_rate'] ?? 0.0),
                1,
            ),
            'failed_customers' => (int) ($current['failed_customers'] ?? 0),
            'recovered_customers' => (int) ($current['recovered_customers'] ?? 0),
            'lost_customers' => (int) ($current['lost_customers'] ?? 0),
            'customer_recovery_rate' => (float) ($current['customer_recovery_rate'] ?? 0.0),
            'failed_value' => $failed['normalized_total'],
            'failed_value_display' => $failed['normalized_display'],
            'failed_value_breakdown' => $failed['source_breakdown'] ?? [],
            'failed_value_normalization_meta' => $failed['normalization_meta'] ?? null,
            'recovered_value' => $recovered['normalized_total'],
            'recovered_value_display' => $recovered['normalized_display'],
            'recovered_value_breakdown' => $recovered['source_breakdown'] ?? [],
            'recovered_value_normalization_meta' => $recovered['normalization_meta'] ?? null,
            'lost_value' => $lost['normalized_total'],
            'lost_value_display' => $lost['normalized_display'],
            'lost_value_breakdown' => $lost['source_breakdown'] ?? [],
            'lost_value_normalization_meta' => $lost['normalization_meta'] ?? null,
            'currency' => $targetCurrency,
            'normalization_partial' => (bool) data_get($failed, 'normalization_meta.partial', false)
                || (bool) data_get($recovered, 'normalization_meta.partial', false)
                || (bool) data_get($lost, 'normalization_meta.partial', false),
        ];
    }

    private function teamExecution(
        Carbon $from,
        Carbon $to,
        Carbon $priorFrom,
        Carbon $priorTo,
        ?array $platformIds,
        string $targetCurrency
    ): array {
        $current = $this->teamExecutionForRange($from, $to, $platformIds);
        $prior = $this->teamExecutionForRange($priorFrom, $priorTo, $platformIds);

        return [
            'active_seconds' => $current['active_seconds'],
            'active_hours' => round($current['active_seconds'] / 3600, 2),
            'prior_active_seconds' => $prior['active_seconds'],
            'prior_active_hours' => round($prior['active_seconds'] / 3600, 2),
            'active_hours_delta_percent' => $this->percentDelta($current['active_seconds'], $prior['active_seconds']),
            'active_members' => $current['active_members'],
            'prior_active_members' => $prior['active_members'],
            'total_actions' => $current['total_actions'],
            'prior_total_actions' => $prior['total_actions'],
            'total_actions_delta_percent' => $this->percentDelta($current['total_actions'], $prior['total_actions']),
            'actions_per_hour' => $current['active_seconds'] > 0
                ? round($current['total_actions'] / ($current['active_seconds'] / 3600), 1)
                : 0.0,
            'prior_actions_per_hour' => $prior['active_seconds'] > 0
                ? round($prior['total_actions'] / ($prior['active_seconds'] / 3600), 1)
                : 0.0,
            'members' => $this->teamMemberExecution($from, $to, $priorFrom, $priorTo, $platformIds, $targetCurrency),
        ];
    }

    private function teamExecutionForRange(Carbon $from, Carbon $to, ?array $platformIds): array
    {
        $sessions = AgentSession::query()
            ->where(function (Builder $query) use ($from, $to) {
                $query->where('started_at', '<=', $to)
                    ->where(function (Builder $endQuery) use ($from) {
                        $endQuery->whereNull('ended_at')
                            ->orWhere('ended_at', '>=', $from);
                    });
            })
            ->get(['user_id', 'started_at', 'last_heartbeat_at', 'ended_at']);

        $seconds = 0;
        foreach ($sessions as $session) {
            $started = $this->safeCarbon($session->started_at);
            $ended = $this->safeCarbon($session->ended_at ?: $session->last_heartbeat_at ?: $session->started_at);

            if (! $started || ! $ended) {
                continue;
            }

            $clampedStart = $started->greaterThan($from) ? $started : $from->copy();
            $clampedEnd = $ended->lessThan($to) ? $ended : $to->copy();
            if ($clampedEnd->greaterThan($clampedStart)) {
                $seconds += $clampedStart->diffInSeconds($clampedEnd);
            }
        }

        $actions = AuditLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->when($platformIds !== null, fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->count();

        return [
            'active_seconds' => (int) $seconds,
            'active_members' => (int) $sessions->pluck('user_id')->filter()->unique()->count(),
            'total_actions' => (int) $actions,
        ];
    }

    private function normalizeRecoveryRows(array $rows, array $fallbackBreakdown, Carbon $eventDate, string $targetCurrency): array
    {
        if ($rows === []) {
            return $this->reportingCurrencyService->normalizeBreakdown($fallbackBreakdown, $eventDate, $targetCurrency, false);
        }

        $platforms = Platform::query()
            ->whereIn('id', collect($rows)->pluck('platform_id')->filter()->unique()->values()->all())
            ->get(['id', 'name', 'country', 'currency_code'])
            ->keyBy('id');

        $eventRows = collect($rows)
            ->map(function (array $row) use ($platforms, $eventDate, $targetCurrency): array {
                $platform = isset($row['platform_id']) ? $platforms->get((int) $row['platform_id']) : null;

                return [
                    'platform_id' => $row['platform_id'] ?? null,
                    'platform_country' => $row['platform_country'] ?? $platform?->country,
                    'platform_name' => $row['platform_name'] ?? $platform?->name,
                    'currency' => $row['currency'] ?? $platform?->currency_code ?? $targetCurrency,
                    'amount' => (float) ($row['amount'] ?? 0),
                    'event_date' => $row['event_date'] ?? $eventDate->toDateString(),
                ];
            })
            ->all();

        return $this->reportingCurrencyService->normalizeEventRows($eventRows, $targetCurrency, false);
    }

    private function teamMemberExecution(
        Carbon $from,
        Carbon $to,
        Carbon $priorFrom,
        Carbon $priorTo,
        ?array $platformIds,
        string $targetCurrency
    ): array {
        $currentRevenue = $this->agentRevenueForRange($from, $to, $platformIds, $targetCurrency);
        $priorRevenue = $this->agentRevenueForRange($priorFrom, $priorTo, $platformIds, $targetCurrency);
        $currentWork = $this->agentWorkForRange($from, $to, $platformIds);
        $priorWork = $this->agentWorkForRange($priorFrom, $priorTo, $platformIds);

        $agentIds = collect(array_keys($currentRevenue))
            ->merge(array_keys($priorRevenue))
            ->merge(array_keys($currentWork))
            ->merge(array_keys($priorWork))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($agentIds->isEmpty()) {
            return [];
        }

        $users = User::query()
            ->whereIn('id', $agentIds->all())
            ->get(['id', 'name', 'role'])
            ->keyBy('id');

        return $agentIds
            ->map(function (int $id) use ($users, $currentRevenue, $priorRevenue, $currentWork, $priorWork, $targetCurrency): array {
                $user = $users->get($id);
                $currentRev = (float) data_get($currentRevenue, "{$id}.normalized_total", 0.0);
                $priorRev = (float) data_get($priorRevenue, "{$id}.normalized_total", 0.0);
                $currentSeconds = (int) data_get($currentWork, "{$id}.active_seconds", 0);
                $priorSeconds = (int) data_get($priorWork, "{$id}.active_seconds", 0);
                $currentActions = (int) data_get($currentWork, "{$id}.actions", 0);
                $priorActions = (int) data_get($priorWork, "{$id}.actions", 0);

                return [
                    'user_id' => $id,
                    'name' => (string) ($user?->name ?: data_get($currentRevenue, "{$id}.name", 'User #'.$id)),
                    'role' => (string) ($user?->role ?: data_get($currentRevenue, "{$id}.role", 'team')),
                    'revenue' => round($currentRev, 2),
                    'prior_revenue' => round($priorRev, 2),
                    'revenue_delta' => round($currentRev - $priorRev, 2),
                    'revenue_delta_percent' => $this->percentDelta($currentRev, $priorRev),
                    'currency' => (string) (data_get($currentRevenue, "{$id}.normalized_currency") ?: $targetCurrency),
                    'payments_count' => (int) data_get($currentRevenue, "{$id}.payments_count", 0),
                    'active_hours' => round($currentSeconds / 3600, 2),
                    'prior_active_hours' => round($priorSeconds / 3600, 2),
                    'active_hours_delta_percent' => $this->percentDelta((float) $currentSeconds, (float) $priorSeconds),
                    'actions' => $currentActions,
                    'prior_actions' => $priorActions,
                    'actions_delta_percent' => $this->percentDelta((float) $currentActions, (float) $priorActions),
                ];
            })
            ->sortByDesc(fn (array $row) => ($row['revenue'] * 1000) + $row['active_hours'] + ($row['actions'] / 1000))
            ->take(8)
            ->values()
            ->all();
    }

    private function agentRevenueForRange(Carbon $from, Carbon $to, ?array $platformIds, string $targetCurrency): array
    {
        $rows = $this->basePayments($from, $to, $platformIds)
            ->join('deals', 'deals.id', '=', 'payments.deal_id')
            ->join('users', 'users.id', '=', 'deals.assigned_to')
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->whereNotNull('deals.assigned_to')
            ->selectRaw('deals.assigned_to as agent_id')
            ->selectRaw('users.name as agent_name')
            ->selectRaw('users.role as agent_role')
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}') as currency")
            ->selectRaw($this->dateExpression().' as event_date')
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(*) as payments_count')
            ->groupBy('deals.assigned_to', 'users.name', 'users.role')
            ->groupBy('payments.platform_id', 'platforms.name', 'platforms.country')
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}')")
            ->groupByRaw($this->dateExpression())
            ->get();

        return $rows
            ->groupBy('agent_id')
            ->map(function (Collection $group) use ($targetCurrency): array {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($group, $targetCurrency, false);

                return [
                    'agent_id' => (int) $group->first()->agent_id,
                    'name' => (string) $group->first()->agent_name,
                    'role' => (string) $group->first()->agent_role,
                    'normalized_total' => (float) ($normalized['normalized_total'] ?? 0),
                    'normalized_currency' => $normalized['normalized_currency'] ?? $targetCurrency,
                    'payments_count' => (int) $group->sum('payments_count'),
                ];
            })
            ->all();
    }

    private function agentWorkForRange(Carbon $from, Carbon $to, ?array $platformIds): array
    {
        $work = [];
        $sessions = AgentSession::query()
            ->whereNotNull('user_id')
            ->where(function (Builder $query) use ($from, $to) {
                $query->where('started_at', '<=', $to)
                    ->where(function (Builder $endQuery) use ($from) {
                        $endQuery->whereNull('ended_at')
                            ->orWhere('ended_at', '>=', $from);
                    });
            })
            ->get(['user_id', 'started_at', 'last_heartbeat_at', 'ended_at']);

        foreach ($sessions as $session) {
            $started = $this->safeCarbon($session->started_at);
            $ended = $this->safeCarbon($session->ended_at ?: $session->last_heartbeat_at ?: $session->started_at);

            if (! $started || ! $ended) {
                continue;
            }

            $clampedStart = $started->greaterThan($from) ? $started : $from->copy();
            $clampedEnd = $ended->lessThan($to) ? $ended : $to->copy();
            if ($clampedEnd->greaterThan($clampedStart)) {
                $userId = (int) $session->user_id;
                $work[$userId]['active_seconds'] = (int) ($work[$userId]['active_seconds'] ?? 0)
                    + $clampedStart->diffInSeconds($clampedEnd);
            }
        }

        $actions = AuditLog::query()
            ->whereNotNull('actor_id')
            ->whereBetween('created_at', [$from, $to])
            ->when($platformIds !== null, fn (Builder $query) => $query->whereIn('platform_id', $platformIds))
            ->selectRaw('actor_id as user_id')
            ->selectRaw('COUNT(*) as actions')
            ->groupBy('actor_id')
            ->pluck('actions', 'user_id');

        foreach ($actions as $userId => $count) {
            $id = (int) $userId;
            $work[$id]['actions'] = (int) $count;
            $work[$id]['active_seconds'] ??= 0;
        }

        return $work;
    }

    private function scorecards(
        array $revenue,
        array $priorRevenue,
        array $customerMovement,
        array $paymentRecovery,
        array $teamExecution,
        array $activeSubscribers,
        array $priorActiveSubscribers,
        int $days
    ): array {
        $currency = (string) ($revenue['normalized_currency'] ?? 'USD');
        $daily = round((float) ($revenue['normalized_total'] ?? 0) / $days, 2);
        $priorDaily = round((float) ($priorRevenue['normalized_total'] ?? 0) / $days, 2);

        return [
            $this->scorecard('revenue', 'Revenue', (float) ($revenue['normalized_total'] ?? 0), (float) ($priorRevenue['normalized_total'] ?? 0), 'money', $currency),
            $this->scorecard('average_daily_revenue', 'Daily average', $daily, $priorDaily, 'money', $currency),
            $this->scorecard('payments_count', 'Payments', (int) ($revenue['payments_count'] ?? 0), (int) ($priorRevenue['payments_count'] ?? 0), 'count'),
            $this->scorecard('new_paid_customers', 'New paid customers', (int) ($customerMovement['new_paid_customers'] ?? 0), data_get($customerMovement, 'new_paid_customers_comparison.prior'), 'count'),
            $this->scorecard('active_subscriber_snapshot', 'User snapshot', (int) ($activeSubscribers['count'] ?? 0), (int) ($priorActiveSubscribers['count'] ?? 0), 'count'),
            $this->scorecard('churned_profiles', 'Churn', (int) ($customerMovement['churned_profiles'] ?? 0), data_get($customerMovement, 'churned_profiles_comparison.prior'), 'count', null, 'lower_is_better'),
            $this->scorecard('lost_value_to_churn', 'Lost to churn', (float) ($customerMovement['lost_value_to_churn'] ?? 0), null, 'money', (string) ($customerMovement['lost_value_to_churn_currency'] ?? 'USD'), 'lower_is_better'),
            $this->scorecard('payment_recovery_rate', 'Payment recovery', (float) ($paymentRecovery['payment_recovery_rate'] ?? 0), (float) ($paymentRecovery['prior_payment_recovery_rate'] ?? 0), 'percent'),
            $this->scorecard('team_active_hours', 'Team active time', (float) ($teamExecution['active_hours'] ?? 0), (float) ($teamExecution['prior_active_hours'] ?? 0), 'hours'),
            $this->scorecard('actions_per_hour', 'Actions/hour', (float) ($teamExecution['actions_per_hour'] ?? 0), (float) ($teamExecution['prior_actions_per_hour'] ?? 0), 'rate'),
        ];
    }

    private function normalizeCountComparison(?array $comparison): ?array
    {
        if ($comparison === null) {
            return null;
        }

        return [
            'current' => (int) ($comparison['current'] ?? 0),
            'prior' => (int) ($comparison['previous'] ?? ($comparison['prior'] ?? 0)),
            'delta' => (int) ($comparison['delta'] ?? 0),
            'delta_percent' => $comparison['percent'] ?? ($comparison['delta_percent'] ?? null),
        ];
    }

    private function scorecard(
        string $key,
        string $label,
        float|int $current,
        float|int|null $prior,
        string $unit,
        ?string $currency = null,
        string $polarity = 'higher_is_better'
    ): array {
        $delta = $prior === null ? null : round((float) $current - (float) $prior, 2);
        $deltaPercent = $prior === null ? null : $this->percentDelta((float) $current, (float) $prior);
        $direction = $prior === null ? 'unknown' : $this->direction((float) $current, (float) $prior);

        return [
            'key' => $key,
            'label' => $label,
            'current' => $current,
            'prior' => $prior,
            'delta' => $delta,
            'delta_percent' => $deltaPercent,
            'direction' => $direction,
            'unit' => $unit,
            'currency' => $currency,
            'polarity' => $polarity,
            'status' => $this->scorecardStatus($direction, $polarity),
        ];
    }

    private function executiveFocus(array $snapshot): array
    {
        $revenue = $snapshot['revenue'] ?? [];
        $markets = $snapshot['market_movement'] ?? [];
        $customers = $snapshot['customer_movement'] ?? [];
        $recovery = $snapshot['payment_recovery'] ?? [];
        $team = $snapshot['team_execution'] ?? [];
        $currency = (string) ($revenue['normalized_currency'] ?? 'USD');
        $total = $currency.' '.number_format((float) ($revenue['normalized_total'] ?? 0), 0);
        $delta = $revenue['delta_percent'] ?? null;
        $headline = 'Revenue '.($delta !== null && $delta < 0 ? 'declined' : 'finished').' at '.$total;

        if ($delta !== null) {
            $headline .= sprintf(' (%s%.1f%% vs %s)', $delta >= 0 ? '+' : '', $delta, data_get($snapshot, 'period.prior_label', 'prior week'));
        }

        $growing = collect($markets['growing'] ?? [])->first();
        $declining = collect($markets['declining'] ?? [])->first();

        return [
            'headline' => $headline,
            'what_changed' => array_values(array_filter([
                sprintf(
                    'Daily revenue averaged %s %s, %s%.1f%% vs %s.',
                    $currency,
                    number_format((float) ($revenue['average_daily'] ?? 0), 0),
                    ((float) ($revenue['average_daily_delta_percent'] ?? 0)) >= 0 ? '+' : '',
                    (float) ($revenue['average_daily_delta_percent'] ?? 0),
                    data_get($snapshot, 'period.prior_label', 'prior week'),
                ),
                $growing ? sprintf('%s added the most growth (%s %s).', $growing['name'], $currency, number_format((float) $growing['delta'], 0)) : null,
                $declining ? sprintf('%s had the sharpest decline (%s %s).', $declining['name'], $currency, number_format(abs((float) $declining['delta']), 0)) : null,
            ])),
            'why_it_matters' => array_values(array_filter([
                sprintf(
                    '%d new paid customers, %d renewed profiles, and %d churned profiles changed the customer base.',
                    (int) ($customers['new_paid_customers'] ?? 0),
                    (int) ($customers['renewed_profiles'] ?? 0),
                    (int) ($customers['churned_profiles'] ?? 0),
                ),
                sprintf(
                    'Churn represents an estimated %s in possible lost weekly value.',
                    (string) ($customers['lost_value_to_churn_display'] ?? $this->formatMoney(0, 'USD')),
                ),
                sprintf(
                    'Payment recovery is %.1f%%, with %s recovered and %s still unrecovered.',
                    (float) ($recovery['payment_recovery_rate'] ?? 0),
                    $this->moneyNarrativeValue($recovery, 'recovered_value', $currency),
                    $this->moneyNarrativeValue($recovery, 'lost_value', $currency),
                ),
                sprintf(
                    'Team active time was %.1fh across %d actions.',
                    (float) ($team['active_hours'] ?? 0),
                    (int) ($team['total_actions'] ?? 0),
                ),
            ])),
            'decision_points' => array_values(array_filter([
                $declining ? sprintf('Review %s decline and decide whether this is market demand, payment friction, or team coverage.', $declining['name']) : null,
                ((float) ($recovery['payment_recovery_rate'] ?? 0)) < 60.0 ? 'Prioritize failed-payment recovery plays before opening new acquisition spend.' : null,
                ((int) ($customers['churned_profiles'] ?? 0)) > ((int) ($customers['new_paid_customers'] ?? 0)) ? 'Assign churn recovery owners before the gap compounds into next week.' : null,
            ])),
        ];
    }

    private function scorecardStatus(string $direction, string $polarity): string
    {
        if ($direction === 'flat' || $direction === 'unknown') {
            return 'neutral';
        }

        $positive = $direction === 'up';
        if ($polarity === 'lower_is_better') {
            $positive = ! $positive;
        }

        return $positive ? 'good' : 'watch';
    }

    private function moneyNarrativeValue(array $source, string $key, string $fallbackCurrency): string
    {
        $display = $source[$key.'_display'] ?? null;
        if (is_string($display) && trim($display) !== '') {
            return $display;
        }

        $value = $source[$key] ?? null;
        if ($value !== null) {
            return $fallbackCurrency.' '.number_format((float) $value, 0);
        }

        $breakdown = (array) ($source[$key.'_breakdown'] ?? []);
        if ($breakdown !== []) {
            return collect($breakdown)
                ->map(fn ($amount, $currency) => strtoupper((string) $currency).' '.number_format((float) $amount, 0))
                ->values()
                ->take(3)
                ->implode(' + ');
        }

        return 'no value recorded';
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
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}') as currency")
            ->selectRaw($this->dateExpression().' as event_date')
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(*) as payments_count')
            ->groupBy('deals.assigned_to', 'users.name', 'users.role')
            ->groupBy('payments.platform_id', 'platforms.name', 'platforms.country')
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}')")
            ->groupByRaw($this->dateExpression())
            ->get();

        return $rows
            ->groupBy('agent_id')
            ->map(function (Collection $group) use ($targetCurrency) {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($group, $targetCurrency, false);

                return [
                    'agent_id' => (int) $group->first()->agent_id,
                    'name' => (string) $group->first()->agent_name,
                    'role' => (string) $group->first()->agent_role,
                    'normalized_total' => (float) ($normalized['normalized_total'] ?? 0),
                    'normalized_currency' => $normalized['normalized_currency'] ?? $targetCurrency,
                    'payments_count' => (int) $group->sum('payments_count'),
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

    private function direction(float|int|null $current, float|int|null $prior): string
    {
        if ($current === null || $prior === null) {
            return 'unknown';
        }

        if ((float) $current > (float) $prior) {
            return 'up';
        }

        if ((float) $current < (float) $prior) {
            return 'down';
        }

        return 'flat';
    }

    private function periodDescriptor(Carbon $from, Carbon $to, Carbon $priorFrom, Carbon $priorTo): array
    {
        return [
            'label' => 'Week '.$from->isoWeek(),
            'iso_week' => sprintf('%d-W%02d', $from->isoWeekYear(), $from->isoWeek()),
            'display' => $this->dateRangeDisplay($from, $to),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'prior_label' => 'Week '.$priorFrom->isoWeek(),
            'prior_iso_week' => sprintf('%d-W%02d', $priorFrom->isoWeekYear(), $priorFrom->isoWeek()),
            'prior_display' => $this->dateRangeDisplay($priorFrom, $priorTo),
            'prior_from' => $priorFrom->toDateString(),
            'prior_to' => $priorTo->toDateString(),
        ];
    }

    private function dateRangeDisplay(Carbon $from, Carbon $to): string
    {
        if ($from->year === $to->year && $from->month === $to->month) {
            return $from->format('j').'-'.$to->format('j M Y');
        }

        if ($from->year === $to->year) {
            return $from->format('j M').'-'.$to->format('j M Y');
        }

        return $from->format('j M Y').'-'.$to->format('j M Y');
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return strtoupper($currency).' '.number_format($amount, 2);
    }

    private function safeCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof CarbonInterface) {
            return Carbon::instance($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function dateExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'date(COALESCE(payments.completed_at, payments.created_at))'
            : 'DATE(COALESCE(payments.completed_at, payments.created_at))';
    }
}
