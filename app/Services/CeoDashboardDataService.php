<?php

namespace App\Services;

use App\Models\ClientActiveSnapshot;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CeoDashboardDataService
{
    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService
    ) {
    }

    public function summary(Request $request): array
    {
        $context = $this->context($request);
        $currentRevenue = $this->revenueTotal($context['from'], $context['to'], $context['platform_id'], $context['target_currency']);
        $priorRevenue = $this->revenueTotal($context['prior_from'], $context['prior_to'], $context['platform_id'], $context['target_currency']);
        $currentActive = $this->activeClientSnapshot($context['to'], $context['platform_id']);
        $startActive = $this->activeClientSnapshot($context['from']->copy()->subDay(), $context['platform_id']);
        $currentActivations = $this->activationCount($context['from'], $context['to'], $context['platform_id']);
        $priorActivations = $this->activationCount($context['prior_from'], $context['prior_to'], $context['platform_id']);
        $currentRenewals = $this->renewalRecovery($context['from'], $context['to'], $context['platform_id']);
        $priorRenewals = $this->renewalRecovery($context['prior_from'], $context['prior_to'], $context['platform_id']);
        $marketPie = $this->marketPie($request)['markets'] ?? [];
        $agentPerformance = $this->agentPerformance($request)['agents'] ?? [];

        return [
            'window' => $this->serializeWindow($context),
            'selected_market' => $context['platform'] ? $this->serializePlatform($context['platform']) : null,
            'metrics' => [
                'collected_revenue' => [
                    'label' => 'Collected Revenue',
                    'value' => $currentRevenue,
                    'prior_value' => $priorRevenue,
                    'delta_percent' => $this->percentDelta($currentRevenue['normalized_total'], $priorRevenue['normalized_total']),
                    'href' => '/payments',
                ],
                'active_clients' => [
                    'label' => 'Active Users',
                    'value' => $currentActive,
                    'prior_value' => $startActive,
                    'delta_percent' => $this->percentDelta($currentActive['count'], $startActive['count']),
                    'href' => '/clients',
                ],
                'new_activations' => [
                    'label' => 'New Activations',
                    'value' => $currentActivations,
                    'prior_value' => $priorActivations,
                    'delta_percent' => $this->percentDelta($currentActivations['count'], $priorActivations['count']),
                    'href' => '/deals?status=active',
                ],
                'renewals_recovered' => [
                    'label' => 'Renewals Recovered',
                    'value' => $currentRenewals,
                    'prior_value' => $priorRenewals,
                    'delta_percent' => $this->percentDelta($currentRenewals['rate'], $priorRenewals['rate']),
                    'href' => '/renewals',
                ],
            ],
            'insights' => $this->insights($marketPie, $agentPerformance, $currentRevenue, $priorRevenue, $currentRenewals),
        ];
    }

    public function marketPie(Request $request): array
    {
        $context = $this->context($request);
        $rows = $this->baseCollectedPayments($context['from'], $context['to'], $context['platform_id'])
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->selectRaw('payments.platform_id')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, '{$context['target_currency']}') as currency")
            ->selectRaw($this->dateExpression() . ' as event_date')
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(*) as payments_count')
            ->groupBy('payments.platform_id', 'platforms.name', 'platforms.country')
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, '{$context['target_currency']}')")
            ->groupByRaw($this->dateExpression())
            ->get();

        $markets = $rows
            ->groupBy('platform_id')
            ->map(function (Collection $group) use ($context) {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($group, $context['target_currency'], false);
                $sourceBreakdown = [];
                foreach ($group as $row) {
                    $currency = strtoupper((string) $row->currency);
                    $sourceBreakdown[$currency] = ($sourceBreakdown[$currency] ?? 0.0) + (float) $row->amount;
                }

                return [
                    'platform_id' => (int) $group->first()->platform_id,
                    'name' => (string) $group->first()->platform_name,
                    'country' => (string) $group->first()->platform_country,
                    'source_breakdown' => $sourceBreakdown,
                    'normalized_total' => $normalized['normalized_total'],
                    'normalized_currency' => $normalized['normalized_currency'],
                    'normalization_meta' => $normalized['normalization_meta'],
                    'payments_count' => (int) $group->sum('payments_count'),
                ];
            })
            ->sortByDesc(fn (array $market) => (float) ($market['normalized_total'] ?? array_sum($market['source_breakdown'])))
            ->values();

        $total = (float) $markets->sum(fn (array $market) => (float) ($market['normalized_total'] ?? array_sum($market['source_breakdown'])));
        $withShares = $markets->map(function (array $market) use ($total) {
            $value = (float) ($market['normalized_total'] ?? array_sum($market['source_breakdown']));
            $market['share_percent'] = $total > 0 ? round(($value / $total) * 100, 1) : 0.0;
            return $market;
        });

        return [
            'window' => $this->serializeWindow($context),
            'selected_market' => $context['platform'] ? $this->serializePlatform($context['platform']) : null,
            'total' => $total,
            'markets' => $withShares->all(),
        ];
    }

    public function revenueTrend(Request $request): array
    {
        $context = $this->context($request);
        $current = $this->bucketedRevenue($context['from'], $context['to'], $context['platform_id'], $context['target_currency'], $context['bucket']);
        $prior = $this->bucketedRevenue($context['prior_from'], $context['prior_to'], $context['platform_id'], $context['target_currency'], $context['bucket']);
        $priorValues = array_values($prior);

        $points = collect(array_values($current))->map(function (array $point, int $index) use ($priorValues) {
            $priorPoint = $priorValues[$index] ?? null;
            return [
                ...$point,
                'prior_label' => $priorPoint['label'] ?? null,
                'prior_value' => $priorPoint['value'] ?? null,
                'prior_source_breakdown' => $priorPoint['source_breakdown'] ?? [],
                'delta_percent' => $this->percentDelta($point['value'], $priorPoint['value'] ?? null),
            ];
        })->all();

        return [
            'window' => $this->serializeWindow($context),
            'bucket' => $context['bucket'],
            'points' => $points,
        ];
    }

    public function recentPayments(Request $request): array
    {
        $context = $this->context($request);
        $payments = Payment::query()
            ->businessVisible()
            ->excludingWalletTopups()
            ->with(['client:id,name', 'platform:id,name,country,currency_code', 'product:id,name', 'deal.assignedAgent:id,name,role', 'confirmedBy:id,name,role'])
            ->where('status', 'completed')
            ->when($context['platform_id'], fn (Builder $query, int $platformId) => $query->where('platform_id', $platformId))
            ->orderByRaw('COALESCE(completed_at, created_at) desc')
            ->limit(10)
            ->get();

        return [
            'window' => $this->serializeWindow($context),
            'payments' => $payments->map(function (Payment $payment) use ($context) {
                $eventDate = $payment->completed_at ?: $payment->created_at;
                $currency = strtoupper((string) ($payment->currency ?: $payment->platform?->currency_code ?: $context['target_currency']));
                $normalized = $this->reportingCurrencyService->normalizeEventRows([
                    (object) [
                        'event_date' => $eventDate?->toDateString() ?: now()->toDateString(),
                        'currency' => $currency,
                        'amount' => (float) $payment->amount,
                        'platform_id' => $payment->platform_id,
                        'platform_country' => $payment->platform?->country,
                        'platform_name' => $payment->platform?->name,
                    ],
                ], $context['target_currency'], false);

                return [
                    'id' => (int) $payment->id,
                    'occurred_at' => optional($eventDate)->toIso8601String(),
                    'client' => $payment->client ? [
                        'id' => (int) $payment->client->id,
                        'name' => $payment->client->name,
                    ] : null,
                    'market' => $payment->platform ? $this->serializePlatform($payment->platform) : null,
                    'product' => $payment->product?->name,
                    'amount' => (float) $payment->amount,
                    'currency' => $currency,
                    'normalized_total' => $normalized['normalized_total'],
                    'normalized_currency' => $normalized['normalized_currency'],
                    'normalization_meta' => $normalized['normalization_meta'],
                    'method' => $this->paymentMethod($payment),
                    'agent' => $payment->deal?->assignedAgent ? [
                        'id' => (int) $payment->deal->assignedAgent->id,
                        'name' => $payment->deal->assignedAgent->name,
                        'role' => $payment->deal->assignedAgent->role,
                    ] : ($payment->confirmedBy ? [
                        'id' => (int) $payment->confirmedBy->id,
                        'name' => $payment->confirmedBy->name,
                        'role' => $payment->confirmedBy->role,
                    ] : null),
                ];
            })->all(),
        ];
    }

    public function agentPerformance(Request $request): array
    {
        $context = $this->context($request);
        $agents = User::query()
            ->where('status', 'active')
            ->whereIn('role', ['sales', 'field_sales', 'sub_admin'])
            ->with('platforms:id,name,country')
            ->orderBy('name')
            ->get();

        $rows = $this->agentRevenueRows($context['from'], $context['to'], $context['platform_id'], $context['target_currency']);
        $priorRows = $this->agentRevenueRows($context['prior_from'], $context['prior_to'], $context['platform_id'], $context['target_currency']);
        $renewals = $this->agentRenewalRows($context['from'], $context['to'], $context['platform_id']);
        $activations = $this->agentActivationRows($context['from'], $context['to'], $context['platform_id']);

        $payload = $agents->map(function (User $agent) use ($rows, $priorRows, $renewals, $activations, $context) {
            $currentGroup = $rows->where('agent_id', $agent->id)->values();
            $priorGroup = $priorRows->where('agent_id', $agent->id)->values();
            $currentRevenue = $this->reportingCurrencyService->normalizeEventRows($currentGroup, $context['target_currency'], false);
            $priorRevenue = $this->reportingCurrencyService->normalizeEventRows($priorGroup, $context['target_currency'], false);
            $sparkline = $this->bucketRows($currentGroup, $context['from'], $context['to'], 'day')
                ->map(fn (Collection $bucketRows, string $label) => [
                    'label' => $label,
                    'value' => $this->reportingCurrencyService->normalizeEventRows($bucketRows, $context['target_currency'], false)['normalized_total'] ?? 0,
                ])
                ->values()
                ->all();
            $renewal = $renewals->get((int) $agent->id, ['recovered' => 0, 'due' => 0]);
            $activation = (int) ($activations->get((int) $agent->id) ?? 0);

            return [
                'id' => (int) $agent->id,
                'name' => $agent->name,
                'role' => $agent->role,
                'markets' => $agent->platforms->map(fn (Platform $platform) => $this->serializePlatform($platform))->values()->all(),
                'revenue' => [
                    'source_breakdown' => $currentRevenue['source_breakdown'],
                    'normalized_total' => $currentRevenue['normalized_total'],
                    'normalized_currency' => $currentRevenue['normalized_currency'],
                    'delta_percent' => $this->percentDelta($currentRevenue['normalized_total'], $priorRevenue['normalized_total']),
                ],
                'renewals' => [
                    'recovered' => (int) $renewal['recovered'],
                    'due' => (int) $renewal['due'],
                    'rate' => (int) $renewal['due'] > 0 ? round(((int) $renewal['recovered'] / (int) $renewal['due']) * 100, 1) : null,
                ],
                'activations' => [
                    'count' => $activation,
                ],
                'sparkline' => $sparkline,
            ];
        })
            ->sortByDesc(fn (array $agent) => (float) ($agent['revenue']['normalized_total'] ?? array_sum($agent['revenue']['source_breakdown'] ?? [])))
            ->values();

        return [
            'window' => $this->serializeWindow($context),
            'agents' => $payload->all(),
        ];
    }

    public function context(Request $request): array
    {
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($request->query('reporting_currency'));
        $horizon = (string) $request->query('horizon', '30d');
        $today = now()->endOfDay();

        if ($horizon === 'custom' && $request->query('from') && $request->query('to')) {
            $from = Carbon::parse((string) $request->query('from'))->startOfDay();
            $to = Carbon::parse((string) $request->query('to'))->endOfDay();
        } elseif ($horizon === 'ytd') {
            $from = now()->startOfYear();
            $to = $today;
        } elseif ($horizon === '90d') {
            $from = now()->subDays(89)->startOfDay();
            $to = $today;
        } else {
            $horizon = '30d';
            $from = now()->subDays(29)->startOfDay();
            $to = $today;
        }

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $days = max(1, $from->diffInDays($to) + 1);
        $priorTo = $from->copy()->subSecond();
        $priorFrom = $priorTo->copy()->subDays($days - 1)->startOfDay();
        $platformId = $request->query('platform_id') ? (int) $request->query('platform_id') : null;
        $platform = $platformId ? Platform::query()->find($platformId) : null;

        return [
            'horizon' => $horizon,
            'from' => $from,
            'to' => $to,
            'prior_from' => $priorFrom,
            'prior_to' => $priorTo,
            'days' => $days,
            'bucket' => $days <= 31 ? 'day' : ($days <= 100 ? 'week' : 'month'),
            'platform_id' => $platform?->id ? (int) $platform->id : null,
            'platform' => $platform,
            'target_currency' => $targetCurrency,
        ];
    }

    private function revenueTotal(Carbon $from, Carbon $to, ?int $platformId, string $targetCurrency): array
    {
        $query = $this->baseCollectedPayments($from, $to, $platformId);
        $normalized = $this->reportingCurrencyService->normalizePaymentQuery(clone $query, $targetCurrency, false);

        return [
            'source_breakdown' => $normalized['source_breakdown'],
            'normalized_total' => $normalized['normalized_total'],
            'normalized_currency' => $normalized['normalized_currency'],
            'normalization_meta' => $normalized['normalization_meta'],
            'payments_count' => (int) (clone $query)->count(),
        ];
    }

    private function baseCollectedPayments(Carbon $from, Carbon $to, ?int $platformId = null): Builder
    {
        return Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) >= ?', [$from->toDateTimeString()])
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) <= ?', [$to->toDateTimeString()])
            ->when($platformId, fn (Builder $query, int $id) => $query->where('payments.platform_id', $id));
    }

    private function activeClientSnapshot(Carbon $date, ?int $platformId): array
    {
        $query = ClientActiveSnapshot::query()
            ->whereDate('date', '<=', $date->toDateString())
            ->when($platformId, fn (Builder $builder, int $id) => $builder->where('platform_id', $id))
            ->orderBy('platform_id')
            ->orderByDesc('date');

        $rows = $query->get()->unique('platform_id')->values();
        $asOf = $rows->pluck('date')->filter()->map(fn ($value) => Carbon::parse($value)->toDateString())->unique()->values()->all();

        return [
            'count' => (int) $rows->sum('count'),
            'as_of' => count($asOf) === 1 ? $asOf[0] : null,
            'approximate' => count($asOf) !== 1 || ($asOf[0] ?? null) !== $date->toDateString(),
        ];
    }

    private function activationCount(Carbon $from, Carbon $to, ?int $platformId): array
    {
        return [
            'count' => (int) Deal::query()
                ->whereNotNull('activated_at')
                ->whereBetween('activated_at', [$from, $to])
                ->when($platformId, fn (Builder $query, int $id) => $query->where('platform_id', $id))
                ->count(),
        ];
    }

    private function renewalRecovery(Carbon $from, Carbon $to, ?int $platformId): array
    {
        $recovered = (int) $this->baseCollectedPayments($from, $to, $platformId)
            ->where(function (Builder $query) {
                $query->where('payments.subscription_lifecycle', 'renewal')
                    ->orWhereExists(function ($subQuery) {
                        $subQuery->selectRaw('1')
                            ->from('deals')
                            ->whereColumn('deals.id', 'payments.deal_id')
                            ->where('deals.subscription_lifecycle', 'renewal');
                    });
            })
            ->count();

        $due = (int) Deal::query()
            ->whereBetween('expires_at', [$from, $to])
            ->when($platformId, fn (Builder $query, int $id) => $query->where('platform_id', $id))
            ->count();

        return [
            'recovered' => $recovered,
            'due' => $due,
            'rate' => $due > 0 ? round(($recovered / $due) * 100, 1) : null,
        ];
    }

    private function bucketedRevenue(Carbon $from, Carbon $to, ?int $platformId, string $targetCurrency, string $bucket): array
    {
        $rows = $this->baseCollectedPayments($from, $to, $platformId)
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->selectRaw($this->dateExpression() . ' as event_date')
            ->selectRaw('payments.platform_id')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}') as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupByRaw($this->dateExpression())
            ->groupBy('payments.platform_id', 'platforms.name', 'platforms.country')
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}')")
            ->get();

        return $this->bucketRows($rows, $from, $to, $bucket)
            ->map(function (Collection $bucketRows, string $label) use ($targetCurrency) {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($bucketRows, $targetCurrency, false);
                return [
                    'label' => $label,
                    'value' => $normalized['normalized_total'] ?? 0,
                    'source_breakdown' => $normalized['source_breakdown'],
                    'normalization_meta' => $normalized['normalization_meta'],
                ];
            })
            ->all();
    }

    private function agentRevenueRows(Carbon $from, Carbon $to, ?int $platformId, string $targetCurrency): Collection
    {
        return $this->baseCollectedPayments($from, $to, $platformId)
            ->join('deals', 'deals.id', '=', 'payments.deal_id')
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->whereNotNull('deals.assigned_to')
            ->selectRaw('deals.assigned_to as agent_id')
            ->selectRaw($this->dateExpression() . ' as event_date')
            ->selectRaw('payments.platform_id')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}') as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupBy('deals.assigned_to', 'payments.platform_id', 'platforms.name', 'platforms.country')
            ->groupByRaw($this->dateExpression())
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, '{$targetCurrency}')")
            ->get();
    }

    private function agentRenewalRows(Carbon $from, Carbon $to, ?int $platformId): Collection
    {
        $recovered = $this->baseCollectedPayments($from, $to, $platformId)
            ->join('deals', 'deals.id', '=', 'payments.deal_id')
            ->whereNotNull('deals.assigned_to')
            ->where(function (Builder $query) {
                $query->where('payments.subscription_lifecycle', 'renewal')
                    ->orWhere('deals.subscription_lifecycle', 'renewal');
            })
            ->selectRaw('deals.assigned_to as agent_id')
            ->selectRaw('COUNT(*) as recovered')
            ->groupBy('deals.assigned_to')
            ->pluck('recovered', 'agent_id');

        $due = Deal::query()
            ->whereNotNull('assigned_to')
            ->whereBetween('expires_at', [$from, $to])
            ->when($platformId, fn (Builder $query, int $id) => $query->where('platform_id', $id))
            ->selectRaw('assigned_to as agent_id')
            ->selectRaw('COUNT(*) as due')
            ->groupBy('assigned_to')
            ->pluck('due', 'agent_id');

        return $due->keys()
            ->merge($recovered->keys())
            ->unique()
            ->mapWithKeys(fn ($agentId) => [(int) $agentId => [
                'recovered' => (int) ($recovered[$agentId] ?? 0),
                'due' => (int) ($due[$agentId] ?? 0),
            ]]);
    }

    private function agentActivationRows(Carbon $from, Carbon $to, ?int $platformId): Collection
    {
        return Deal::query()
            ->whereNotNull('assigned_to')
            ->whereNotNull('activated_at')
            ->whereBetween('activated_at', [$from, $to])
            ->when($platformId, fn (Builder $query, int $id) => $query->where('platform_id', $id))
            ->selectRaw('assigned_to as agent_id')
            ->selectRaw('COUNT(*) as activations')
            ->groupBy('assigned_to')
            ->pluck('activations', 'agent_id');
    }

    private function bucketRows(Collection $rows, Carbon $from, Carbon $to, string $bucket): Collection
    {
        $labels = collect();
        $cursor = $this->bucketStart($from, $bucket);
        $end = $this->bucketStart($to, $bucket);

        while ($cursor->lte($end)) {
            $labels->put($this->bucketLabel($cursor, $bucket), collect());
            $cursor = match ($bucket) {
                'month' => $cursor->copy()->addMonth(),
                'week' => $cursor->copy()->addWeek(),
                default => $cursor->copy()->addDay(),
            };
        }

        foreach ($rows as $row) {
            $date = Carbon::parse((string) $row->event_date);
            $label = $this->bucketLabel($this->bucketStart($date, $bucket), $bucket);
            if ($labels->has($label)) {
                $labels->put($label, $labels->get($label)->push($row));
            }
        }

        return $labels;
    }

    private function bucketStart(Carbon $date, string $bucket): Carbon
    {
        return match ($bucket) {
            'month' => $date->copy()->startOfMonth(),
            'week' => $date->copy()->startOfWeek(),
            default => $date->copy()->startOfDay(),
        };
    }

    private function bucketLabel(Carbon $date, string $bucket): string
    {
        return match ($bucket) {
            'month' => $date->format('Y-m'),
            'week' => $date->format('Y-\WW'),
            default => $date->toDateString(),
        };
    }

    private function insights(array $markets, array $agents, array $currentRevenue, array $priorRevenue, array $renewals): array
    {
        $topMarket = $markets[0] ?? null;
        $topAgent = $agents[0] ?? null;
        $revenueDelta = $this->percentDelta($currentRevenue['normalized_total'], $priorRevenue['normalized_total']);

        return array_values(array_filter([
            $topMarket ? [
                'key' => 'top_market',
                'tone' => 'market',
                'label' => 'Leading market',
                'message' => sprintf('%s holds %s%% of collected revenue.', $topMarket['name'], number_format((float) $topMarket['share_percent'], 1)),
                'platform_id' => $topMarket['platform_id'],
            ] : null,
            $topAgent ? [
                'key' => 'top_agent',
                'tone' => 'agent',
                'label' => 'Top producer',
                'message' => sprintf('%s leads the floor this period.', $topAgent['name']),
                'agent_id' => $topAgent['id'],
            ] : null,
            [
                'key' => 'cash_velocity',
                'tone' => ($revenueDelta ?? 0) >= 0 ? 'positive' : 'warning',
                'label' => 'Cash velocity',
                'message' => $revenueDelta === null
                    ? 'No prior-window revenue baseline yet.'
                    : sprintf('Collected revenue is %s%s%% vs. prior window.', $revenueDelta >= 0 ? '+' : '', number_format($revenueDelta, 1)),
            ],
            [
                'key' => 'renewal_risk',
                'tone' => (($renewals['rate'] ?? 0) >= 70) ? 'positive' : 'warning',
                'label' => 'Renewal recovery',
                'message' => sprintf('%d of %d due renewals recovered.', (int) $renewals['recovered'], (int) $renewals['due']),
            ],
        ]));
    }

    private function dateExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'date(COALESCE(payments.completed_at, payments.created_at))'
            : 'DATE(COALESCE(payments.completed_at, payments.created_at))';
    }

    private function paymentMethod(Payment $payment): array
    {
        $provider = trim((string) $payment->provider_key);
        if ($provider !== '') {
            return [
                'label' => Str::of($provider)->replace('_', ' ')->title()->replace('Mpesa', 'M-Pesa')->toString(),
                'subtitle' => null,
                'source' => 'provider_key',
            ];
        }

        if ((string) $payment->source === 'manual') {
            $subtitle = data_get($payment->payment_data, 'manual_submission_type')
                ?? data_get($payment->raw_payload, 'manual_submission_type');

            return [
                'label' => 'Manual',
                'subtitle' => $subtitle ? Str::of((string) $subtitle)->replace('_', ' ')->title()->toString() : null,
                'source' => 'manual',
            ];
        }

        $source = trim((string) $payment->source);

        return [
            'label' => $source !== '' ? Str::of($source)->replace('_', ' ')->title()->toString() : 'Unknown',
            'subtitle' => null,
            'source' => 'source',
        ];
    }

    private function serializeWindow(array $context): array
    {
        return [
            'horizon' => $context['horizon'],
            'from' => $context['from']->toDateString(),
            'to' => $context['to']->toDateString(),
            'prior_from' => $context['prior_from']->toDateString(),
            'prior_to' => $context['prior_to']->toDateString(),
            'days' => $context['days'],
            'bucket' => $context['bucket'],
            'target_currency' => $context['target_currency'],
        ];
    }

    private function serializePlatform(Platform $platform): array
    {
        return [
            'id' => (int) $platform->id,
            'name' => $platform->name,
            'country' => $platform->country,
            'currency_code' => $platform->currency_code,
        ];
    }

    private function percentDelta($current, $prior): ?float
    {
        if ($current === null || $prior === null || (float) $prior == 0.0) {
            return null;
        }

        return round((((float) $current - (float) $prior) / abs((float) $prior)) * 100, 1);
    }
}
