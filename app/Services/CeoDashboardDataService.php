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
        $currentCustomerMix = $this->customerRevenueMix($context['from'], $context['to'], $context['platform_id'], $context['target_currency']);
        $priorCustomerMix = $this->customerRevenueMix($context['prior_from'], $context['prior_to'], $context['platform_id'], $context['target_currency']);
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
                'new_user_revenue' => [
                    'label' => 'New User Revenue',
                    'value' => $currentCustomerMix['buckets']['new_active'],
                    'prior_value' => $priorCustomerMix['buckets']['new_active'],
                    'delta_percent' => $this->percentDelta($currentCustomerMix['buckets']['new_active']['normalized_amount'], $priorCustomerMix['buckets']['new_active']['normalized_amount']),
                    'href' => '/payments?customer_mix_segment=new_active',
                ],
                'existing_user_revenue' => [
                    'label' => 'Existing User Revenue',
                    'value' => $currentCustomerMix['buckets']['existing_active'],
                    'prior_value' => $priorCustomerMix['buckets']['existing_active'],
                    'delta_percent' => $this->percentDelta($currentCustomerMix['buckets']['existing_active']['normalized_amount'], $priorCustomerMix['buckets']['existing_active']['normalized_amount']),
                    'href' => '/payments?customer_mix_segment=existing_active',
                ],
            ],
            'customer_mix' => $currentCustomerMix,
            'insights' => $this->insights($marketPie, $agentPerformance, $currentRevenue, $priorRevenue, $currentCustomerMix),
        ];
    }

    public function marketPie(Request $request): array
    {
        $context = $this->context($request);
        $payments = $this->baseCollectedPayments($context['from'], $context['to'], $context['platform_id'])
            ->select([
                'id',
                'platform_id',
                'amount',
                'currency',
                'completed_at',
                'created_at',
                'provider_key',
                'source',
                'payment_data',
                'manual_payment_bundle_id',
                'match_confidence',
                'confirmed_by',
                'confirmed_at',
                'reconciliation_state',
                'transaction_reference',
                'reference_number',
            ])
            ->with([
                'platform:id,name,country,currency_code',
                'routingDecisions:id,payment_id,provider_type_key,execution_mode,environment,created_at',
                'providerTransactions:id,payment_id,provider_type_key,created_at,last_status_at',
                'manualSubmission:id,payment_id,manual_method_key,review_decision,reviewed_at',
            ])
            ->get();

        $rows = $payments->map(function (Payment $payment) use ($context) {
            $eventDate = $payment->completed_at ?: $payment->created_at;

            return (object) [
                'payment_id' => $payment->id,
                'platform_id' => $payment->platform_id,
                'platform_name' => $payment->platform?->name ?: 'Unassigned market',
                'platform_country' => $payment->platform?->country ?: '',
                'provider_key' => $payment->provider_key,
                'source' => $payment->source,
                'channel_key' => $this->paymentChannel($payment)['key'],
                'currency' => strtoupper((string) ($payment->currency ?: $payment->platform?->currency_code ?: $context['target_currency'])),
                'event_date' => $eventDate?->toDateString() ?: now()->toDateString(),
                'amount' => (float) $payment->amount,
                'payments_count' => 1,
            ];
        });

        $markets = $rows
            ->groupBy('platform_id')
            ->map(function (Collection $group) use ($context) {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($group, $context['target_currency'], false);
                $sourceBreakdown = [];
                foreach ($group as $row) {
                    $currency = strtoupper((string) $row->currency);
                    $sourceBreakdown[$currency] = ($sourceBreakdown[$currency] ?? 0.0) + (float) $row->amount;
                }

                $channels = $group
                    ->groupBy('channel_key')
                    ->map(function (Collection $channelRows) use ($context) {
                        $channel = $this->paymentChannelFromKey((string) $channelRows->first()->channel_key);
                        $normalized = $this->reportingCurrencyService->normalizeEventRows($channelRows, $context['target_currency'], false);
                        $breakdown = [];

                        foreach ($channelRows as $row) {
                            $currency = strtoupper((string) $row->currency);
                            $breakdown[$currency] = ($breakdown[$currency] ?? 0.0) + (float) $row->amount;
                        }

                        return [
                            'key' => $channel['key'],
                            'label' => $channel['label'],
                            'description' => $channel['description'],
                            'source_breakdown' => $breakdown,
                            'normalized_total' => $normalized['normalized_total'],
                            'normalized_currency' => $normalized['normalized_currency'],
                            'payments_count' => (int) $channelRows->sum('payments_count'),
                        ];
                    })
                    ->sortByDesc(fn (array $channel) => (float) ($channel['normalized_total'] ?? array_sum($channel['source_breakdown'])))
                    ->values();

                $market = [
                    'platform_id' => (int) $group->first()->platform_id,
                    'name' => (string) $group->first()->platform_name,
                    'country' => (string) $group->first()->platform_country,
                    'source_breakdown' => $sourceBreakdown,
                    'normalized_total' => $normalized['normalized_total'],
                    'normalized_currency' => $normalized['normalized_currency'],
                    'normalization_meta' => $normalized['normalization_meta'],
                    'payments_count' => (int) $group->sum('payments_count'),
                    'channels' => $channels->all(),
                ];

                $market['primary_channel'] = $channels->first();

                return $market;
            })
            ->sortByDesc(fn (array $market) => (float) ($market['normalized_total'] ?? array_sum($market['source_breakdown'])))
            ->values();

        $total = (float) $markets->sum(fn (array $market) => (float) ($market['normalized_total'] ?? array_sum($market['source_breakdown'])));
        $withShares = $markets->map(function (array $market) use ($total) {
            $value = (float) ($market['normalized_total'] ?? array_sum($market['source_breakdown']));
            $market['share_percent'] = $total > 0 ? round(($value / $total) * 100, 1) : 0.0;
            $market['channels'] = collect($market['channels'] ?? [])
                ->map(function (array $channel) use ($value) {
                    $channelValue = (float) ($channel['normalized_total'] ?? array_sum($channel['source_breakdown'] ?? []));
                    $channel['share_percent'] = $value > 0 ? round(($channelValue / $value) * 100, 1) : 0.0;
                    return $channel;
                })
                ->values()
                ->all();
            return $market;
        });

        return [
            'window' => $this->serializeWindow($context),
            'selected_market' => $context['platform'] ? $this->serializePlatform($context['platform']) : null,
            'total' => $total,
            'channels' => $this->summarizeChannels($rows, $context['target_currency'], $total),
            'markets' => $withShares->all(),
        ];
    }

    public function revenueTrend(Request $request): array
    {
        $context = $this->context($request);
        $bucket = $this->resolveTrendBucket($request, $context['bucket']);
        $current = $this->bucketedRevenue($context['from'], $context['to'], $context['platform_id'], $context['target_currency'], $bucket);
        $prior = $this->bucketedRevenue($context['prior_from'], $context['prior_to'], $context['platform_id'], $context['target_currency'], $bucket);
        $priorValues = array_values($prior);

        $points = collect(array_values($current))->map(function (array $point, int $index) use ($priorValues) {
            $priorPoint = $priorValues[$index] ?? null;
            return [
                ...$point,
                'prior_label' => $priorPoint['label'] ?? null,
                'prior_value' => $priorPoint['value'] ?? null,
                'prior_payments_count' => $priorPoint['payments_count'] ?? 0,
                'prior_average_ticket' => $priorPoint['average_ticket'] ?? 0,
                'prior_source_breakdown' => $priorPoint['source_breakdown'] ?? [],
                'delta_percent' => $this->percentDelta($point['value'], $priorPoint['value'] ?? null),
            ];
        })->all();

        return [
            'window' => $this->serializeWindow($context),
            'bucket' => $bucket,
            'points' => $points,
        ];
    }

    public function recentPayments(Request $request): array
    {
        $context = $this->context($request);
        $limit = $this->resolveRecentPaymentLimit($request);
        $channelFilter = $this->resolveChannelFilter($request);
        $payments = Payment::query()
            ->businessVisible()
            ->excludingWalletTopups()
            ->with([
                'client:id,name',
                'platform:id,name,country,currency_code',
                'product:id,name',
                'deal.assignedAgent:id,name,role',
                'confirmedBy:id,name,role',
                'routingDecisions:id,payment_id,provider_type_key,execution_mode,environment,created_at',
                'providerTransactions:id,payment_id,provider_type_key,created_at,last_status_at',
                'manualSubmission:id,payment_id,manual_method_key,review_decision,reviewed_at',
            ])
            ->where('status', 'completed')
            ->when($context['platform_id'], fn (Builder $query, int $platformId) => $query->where('platform_id', $platformId))
            ->when($channelFilter !== 'all', fn (Builder $query) => $this->applyPaymentChannelFilter($query, $channelFilter))
            ->orderByRaw('COALESCE(completed_at, created_at) desc')
            ->limit($limit)
            ->get();

        return [
            'window' => $this->serializeWindow($context),
            'limit' => $limit,
            'channel_filter' => $channelFilter,
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
                    'channel' => $this->paymentChannel($payment),
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
                    'renewal_payments' => (int) ($renewal['renewal_payments'] ?? $renewal['recovered']),
                    'due_window_base' => (int) ($renewal['due_window_base'] ?? $renewal['due']),
                    'overflow_recoveries' => (int) ($renewal['overflow_recoveries'] ?? 0),
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

    private function resolveTrendBucket(Request $request, string $fallback): string
    {
        $bucket = strtolower(trim((string) $request->query('bucket', $fallback)));

        return in_array($bucket, ['day', 'week', 'month'], true) ? $bucket : $fallback;
    }

    private function resolveRecentPaymentLimit(Request $request): int
    {
        $limit = (int) $request->query('limit', 10);

        return in_array($limit, [10, 20, 30], true) ? $limit : 10;
    }

    private function resolveChannelFilter(Request $request): string
    {
        $filter = strtolower(trim((string) $request->query('channel', 'all')));

        return in_array($filter, ['all', 'self_service', 'manual', 'other'], true) ? $filter : 'all';
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

    private function customerRevenueMix(Carbon $from, Carbon $to, ?int $platformId, string $targetCurrency): array
    {
        $baseQuery = $this->baseCollectedPayments($from, $to, $platformId);
        $bucketKeys = ['new_active', 'existing_active', 'unattributed', 'other_matched'];
        $buckets = [];

        foreach ($bucketKeys as $bucketKey) {
            $bucketQuery = $this->applyCustomerMixBucketScope(clone $baseQuery, $bucketKey, $from, $to);
            $normalized = $this->reportingCurrencyService->normalizePaymentQuery(clone $bucketQuery, $targetCurrency, false);
            $countQuery = clone $bucketQuery;

            $buckets[$bucketKey] = [
                'key' => $bucketKey,
                'payments_count' => (int) (clone $bucketQuery)->count(),
                'clients_count' => (int) $countQuery
                    ->whereNotNull('payments.client_id')
                    ->distinct('payments.client_id')
                    ->count('payments.client_id'),
                'source_breakdown' => $normalized['source_breakdown'],
                'normalized_amount' => $normalized['normalized_total'],
                'normalized_currency' => $normalized['normalized_currency'],
                'normalization_meta' => $normalized['normalization_meta'],
                'share_percent' => 0.0,
            ];
        }

        $total = (float) array_sum(array_map(
            static fn (array $bucket) => (float) ($bucket['normalized_amount'] ?? 0),
            $buckets
        ));

        foreach ($buckets as $bucketKey => $bucket) {
            $amount = (float) ($bucket['normalized_amount'] ?? 0);
            $buckets[$bucketKey]['share_percent'] = $total > 0 ? round(($amount / $total) * 100, 1) : 0.0;
        }

        return [
            'total' => $total,
            'target_currency' => $targetCurrency,
            'buckets' => $buckets,
        ];
    }

    private function applyCustomerMixBucketScope(Builder $query, string $bucketKey, Carbon $from, Carbon $to): Builder
    {
        return match ($bucketKey) {
            'new_active' => $query->whereHas('client', function (Builder $clientQuery) use ($from, $to) {
                $clientQuery->active()
                    ->where('created_at', '>=', $from)
                    ->where('created_at', '<=', $to);
            }),
            'existing_active' => $query->whereHas('client', function (Builder $clientQuery) use ($from) {
                $clientQuery->active()
                    ->where('created_at', '<', $from);
            }),
            'unattributed' => $query->whereNull('payments.client_id'),
            'other_matched' => $query
                ->whereNotNull('payments.client_id')
                ->where(function (Builder $builder) use ($to) {
                    $builder->whereDoesntHave('client')
                        ->orWhereHas('client', function (Builder $clientQuery) use ($to) {
                            $clientQuery->where(function (Builder $nonActiveOrOutOfPeriod) use ($to) {
                                $nonActiveOrOutOfPeriod
                                    ->whereNull('profile_status')
                                    ->orWhere('profile_status', '!=', 'publish')
                                    ->orWhere('needs_payment', true)
                                    ->orWhere('notactive', true)
                                    ->orWhere('created_at', '>', $to);
                            });
                        });
                }),
            default => $query,
        };
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
            ->selectRaw('COUNT(*) as payments_count')
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
                    'payments_count' => (int) $bucketRows->sum('payments_count'),
                    'average_ticket' => (int) $bucketRows->sum('payments_count') > 0
                        ? round((float) ($normalized['normalized_total'] ?? 0) / (int) $bucketRows->sum('payments_count'), 2)
                        : 0,
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
            ->mapWithKeys(function ($agentId) use ($recovered, $due) {
                $paymentsRecovered = (int) ($recovered[$agentId] ?? 0);
                $dueWindowBase = (int) ($due[$agentId] ?? 0);
                $rateBase = max($dueWindowBase, $paymentsRecovered);

                return [(int) $agentId => [
                    'recovered' => min($paymentsRecovered, $rateBase),
                    'renewal_payments' => $paymentsRecovered,
                    'due' => $rateBase,
                    'due_window_base' => $dueWindowBase,
                    'overflow_recoveries' => max(0, $paymentsRecovered - $dueWindowBase),
                ]];
            });
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

    private function insights(array $markets, array $agents, array $currentRevenue, array $priorRevenue, array $customerMix): array
    {
        $topMarket = $markets[0] ?? null;
        $topAgent = $agents[0] ?? null;
        $revenueDelta = $this->percentDelta($currentRevenue['normalized_total'], $priorRevenue['normalized_total']);
        $existingShare = (float) data_get($customerMix, 'buckets.existing_active.share_percent', 0);
        $newShare = (float) data_get($customerMix, 'buckets.new_active.share_percent', 0);

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
                'key' => 'customer_mix',
                'tone' => $existingShare >= $newShare ? 'positive' : 'market',
                'label' => 'Customer mix',
                'message' => sprintf('Existing users contribute %s%% vs. %s%% from new users.', number_format($existingShare, 1), number_format($newShare, 1)),
            ],
        ]));
    }

    private function summarizeChannels(Collection $rows, string $targetCurrency, float $total): array
    {
        return $rows
            ->groupBy('channel_key')
            ->map(function (Collection $channelRows) use ($targetCurrency, $total) {
                $channel = $this->paymentChannelFromKey((string) $channelRows->first()->channel_key);
                $normalized = $this->reportingCurrencyService->normalizeEventRows($channelRows, $targetCurrency, false);
                $value = (float) ($normalized['normalized_total'] ?? 0);
                $breakdown = [];

                foreach ($channelRows as $row) {
                    $currency = strtoupper((string) $row->currency);
                    $breakdown[$currency] = ($breakdown[$currency] ?? 0.0) + (float) $row->amount;
                }

                return [
                    'key' => $channel['key'],
                    'label' => $channel['label'],
                    'description' => $channel['description'],
                    'source_breakdown' => $breakdown,
                    'normalized_total' => $normalized['normalized_total'],
                    'normalized_currency' => $normalized['normalized_currency'],
                    'payments_count' => (int) $channelRows->sum('payments_count'),
                    'share_percent' => $total > 0 ? round(($value / $total) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc(fn (array $channel) => (float) ($channel['normalized_total'] ?? array_sum($channel['source_breakdown'] ?? [])))
            ->values()
            ->all();
    }

    private function applyPaymentChannelFilter(Builder $query, string $channel): void
    {
        if ($channel === 'manual') {
            $this->whereManualChannel($query);
            return;
        }

        if ($channel === 'self_service') {
            $this->whereSelfServiceChannel($query);
            return;
        }

        if ($channel === 'other') {
            $query->where(function (Builder $builder) {
                $builder->whereDoesntHave('manualSubmission')
                    ->whereNull('manual_payment_bundle_id')
                    ->whereRaw("LOWER(COALESCE(provider_key, '')) NOT LIKE ?", ['%manual%'])
                    ->whereRaw("LOWER(COALESCE(source, '')) NOT LIKE ?", ['%manual%'])
                    ->whereRaw("LOWER(COALESCE(match_confidence, '')) != ?", ['manual'])
                    ->whereNull('confirmed_by')
                    ->whereDoesntHave('routingDecisions', function (Builder $decisionQuery) {
                        $decisionQuery->whereNotNull('provider_type_key')
                            ->whereRaw("LOWER(COALESCE(provider_type_key, '')) NOT LIKE ?", ['%manual%']);
                    })
                    ->whereDoesntHave('providerTransactions')
                    ->where(function (Builder $providerBuilder) {
                        $providerBuilder->whereNull('provider_key')
                            ->orWhere('provider_key', '');
                    })
                    ->where(function (Builder $sourceBuilder) {
                        $sourceBuilder->whereNull('source')
                            ->orWhereNotIn('source', ['hosted_checkout', 'payment_link', 'checkout', 'self_service', 'self_checkout']);
                    });
            });
        }
    }

    private function whereManualChannel(Builder $query): void
    {
        $query->where(function (Builder $builder) {
            $builder->whereHas('manualSubmission')
                ->orWhereNotNull('manual_payment_bundle_id')
                ->orWhereRaw("LOWER(COALESCE(provider_key, '')) LIKE ?", ['%manual%'])
                ->orWhereRaw("LOWER(COALESCE(source, '')) LIKE ?", ['%manual%'])
                ->orWhereRaw("LOWER(COALESCE(match_confidence, '')) = ?", ['manual'])
                ->orWhere(function (Builder $legacyAgentEntry) {
                    $legacyAgentEntry->whereDoesntHave('routingDecisions')
                        ->whereDoesntHave('providerTransactions')
                        ->where(function (Builder $providerBuilder) {
                            $providerBuilder->whereNull('provider_key')
                                ->orWhere('provider_key', '');
                        })
                        ->where(function (Builder $referenceBuilder) {
                            $referenceBuilder->whereRaw("UPPER(COALESCE(transaction_reference, '')) LIKE ?", ['UE%'])
                                ->orWhereRaw("UPPER(COALESCE(reference_number, '')) LIKE ?", ['UE%']);
                        });
                });
        });
    }

    private function whereSelfServiceChannel(Builder $query): void
    {
        $query
            ->whereDoesntHave('manualSubmission')
            ->whereNull('manual_payment_bundle_id')
            ->whereRaw("LOWER(COALESCE(provider_key, '')) NOT LIKE ?", ['%manual%'])
            ->whereRaw("LOWER(COALESCE(source, '')) NOT LIKE ?", ['%manual%'])
            ->whereRaw("LOWER(COALESCE(match_confidence, '')) != ?", ['manual'])
            ->where(function (Builder $builder) {
                $builder->whereHas('routingDecisions', function (Builder $decisionQuery) {
                    $decisionQuery->whereNotNull('provider_type_key')
                        ->whereRaw("LOWER(COALESCE(provider_type_key, '')) NOT LIKE ?", ['%manual%']);
                })
                    ->orWhereHas('providerTransactions')
                    ->orWhere(function (Builder $providerBuilder) {
                        $providerBuilder->whereNotNull('provider_key')
                            ->where('provider_key', '!=', '')
                            ->whereRaw("LOWER(COALESCE(provider_key, '')) NOT LIKE ?", ['%manual%']);
                    })
                    ->orWhereIn('source', ['hosted_checkout', 'payment_link', 'checkout', 'self_service', 'self_checkout']);
            });
    }

    private function dateExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? 'date(COALESCE(payments.completed_at, payments.created_at))'
            : 'DATE(COALESCE(payments.completed_at, payments.created_at))';
    }

    private function paymentMethod(Payment $payment): array
    {
        $payment->loadMissing('manualSubmission');

        if ($payment->manualSubmission) {
            return [
                'label' => 'Manual proof',
                'subtitle' => $payment->manualSubmission->manual_method_key
                    ? Str::of((string) $payment->manualSubmission->manual_method_key)->replace('_', ' ')->title()->toString()
                    : null,
                'source' => 'manual_submission',
            ];
        }

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

    private function paymentChannel(Payment $payment): array
    {
        $payment->loadMissing(['routingDecisions', 'providerTransactions', 'manualSubmission']);

        $provider = strtolower(trim((string) $payment->provider_key));
        $source = strtolower(trim((string) $payment->source));
        $origin = strtolower(trim((string) data_get($payment->payment_data, 'origin', '')));
        $billingSurface = strtolower(trim((string) data_get($payment->payment_data, 'billing_surface', '')));
        $matchConfidence = strtolower(trim((string) $payment->match_confidence));
        $reference = strtoupper(trim((string) ($payment->transaction_reference ?: $payment->reference_number)));

        if (
            $payment->manualSubmission
            || $payment->manual_payment_bundle_id
            || str_contains($provider, 'manual')
            || str_contains($source, 'manual')
            || str_contains($origin, 'manual')
            || str_contains($billingSurface, 'manual')
            || $matchConfidence === 'manual'
        ) {
            $label = $payment->manualSubmission ? 'Manual proof' : 'Agent manual';

            return [
                'key' => 'manual',
                'label' => $label,
                'description' => $payment->manualSubmission
                    ? 'Customer-submitted proof reviewed through the manual payment route.'
                    : 'Recorded by an agent after the customer shared an offline payment code.',
            ];
        }

        $routingDecision = $payment->routingDecisions
            ->sortByDesc(fn ($decision) => optional($decision->created_at)->getTimestamp() ?? 0)
            ->first();
        $hasStructuredProviderRoute = ($routingDecision && trim((string) $routingDecision->provider_type_key) !== '')
            || $payment->providerTransactions->isNotEmpty()
            || $provider !== '';

        if (!$hasStructuredProviderRoute && str_starts_with($reference, 'UE')) {
            return [
                'key' => 'manual',
                'label' => 'Agent manual',
                'description' => 'Legacy agent-entered payment reference without a provider route or transaction.',
            ];
        }

        if (
            $hasStructuredProviderRoute
            || in_array($source, ['hosted_checkout', 'payment_link', 'checkout', 'self_service', 'self_checkout'], true)
        ) {
            $providerName = $routingDecision?->provider_type_key ?: $payment->providerTransactions->first()?->provider_type_key ?: $payment->provider_key;

            return [
                'key' => 'self_service',
                'label' => 'Self-service',
                'description' => $providerName
                    ? sprintf('Activated through a provider route such as %s.', Str::headline((string) $providerName))
                    : 'Activated through payment links, hosted checkout, STK, or provider callbacks.',
            ];
        }

        return $this->paymentChannelFromValues($payment->provider_key, $payment->source);
    }

    private function paymentChannelFromValues($providerKey, $source): array
    {
        $provider = trim((string) $providerKey);
        $sourceValue = strtolower(trim((string) $source));

        if (str_contains(strtolower($provider), 'manual') || str_contains($sourceValue, 'manual')) {
            return [
                'key' => 'manual',
                'label' => 'Manual',
                'description' => 'Recorded by the team from an offline or direct confirmation.',
            ];
        }

        if ($provider !== '' || in_array($sourceValue, ['hosted_checkout', 'payment_link', 'checkout', 'self_service', 'self_checkout'], true)) {
            return [
                'key' => 'self_service',
                'label' => 'Self-service',
                'description' => 'Collected through payment links, hosted checkout, STK, or provider callbacks.',
            ];
        }

        return [
            'key' => 'other',
            'label' => 'Other',
            'description' => 'Imported or legacy records without a clear collection channel.',
        ];
    }

    private function paymentChannelFromKey(string $key): array
    {
        return match ($key) {
            'manual' => [
                'key' => 'manual',
                'label' => 'Manual',
                'description' => 'Agent-entered codes or customer-submitted proof.',
            ],
            'self_service' => [
                'key' => 'self_service',
                'label' => 'Self-service',
                'description' => 'Provider-routed collection such as PawaPay, checkout, STK, or callbacks.',
            ],
            default => [
                'key' => 'other',
                'label' => 'Other',
                'description' => 'Imported or legacy records without a clear collection channel.',
            ],
        };
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
