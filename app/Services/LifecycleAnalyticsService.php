<?php

namespace App\Services;

use App\Models\BillingProxySession;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\TimelineEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read-side observability + conversion analytics for the lifecycle SMS engine.
 *
 * Attribution model (last-touch): every lifecycle send is a row in
 * timeline_events (event_type=lifecycle_sms, status=sent) carrying its flow and
 * the payment it pointed at. A conversion is a completed subscription payment by
 * the client. Each conversion is credited to that client's most recent send
 * within the attribution window before it — so a client with several sends is
 * never double-counted. A conversion whose paid payment IS the send's own link
 * payment is "direct"; any other in-window activation is "assisted".
 *
 * Opens come from billing_proxy_sessions (opened_at/open_count) joined by
 * payment_id. Money is normalised to USD via ReportingCurrencyService so the
 * "all markets" view never mixes currencies.
 */
class LifecycleAnalyticsService
{
    /** Hard cap on sends scanned per query — guards a runaway range. */
    private const MAX_SENDS = 20000;

    public const FLOWS = ['onboarding', 'recovery', 'reactivation', 'renewal'];

    public function __construct(
        private readonly LifecycleSmsSettingsService $settings,
        private readonly ReportingCurrencyService $reportingCurrency
    ) {
    }

    public function defaultWindowDays(): int
    {
        return (int) ($this->settings->currentConfig()['attribution_window_days'] ?? 7);
    }

    /**
     * @param array{from?:string,to?:string,platform_id?:int|null,window_days?:int|null} $filters
     */
    public function overview(array $filters = []): array
    {
        [$from, $to, $platformId, $windowDays] = $this->resolveFilters($filters);
        $targetCurrency = 'USD';

        // 1) Sends in range.
        $sends = $this->loadSends($from, $to, $platformId);
        $capped = $sends->count() >= self::MAX_SENDS;

        $sendPaymentIds = $sends->pluck('payment_id')->filter()->unique()->values();
        $clientIds = $sends->pluck('client_id')->filter()->unique()->values();

        // 2) Opens for the sends' link payments.
        $openedPaymentIds = $sendPaymentIds->isEmpty()
            ? collect()
            : BillingProxySession::query()
                ->whereIn('payment_id', $sendPaymentIds->all())
                ->whereNotNull('opened_at')
                ->pluck('payment_id')
                ->map(fn ($id) => (int) $id)
                ->unique();
        $openedSet = $openedPaymentIds->flip();

        // 3) Conversions: completed subscription payments by these clients, from
        //    the first send up to `to`+window (so late conversions still attach).
        $conversions = $clientIds->isEmpty()
            ? collect()
            : $this->loadConversions($clientIds, $from, $to->copy()->addDays($windowDays), $platformId);

        // 4) Last-touch attribution, then reconstruct the credited-send rows.
        $attributionBySendId = $this->attribute($sends, $conversions, $windowDays);
        $attributed = $sends
            ->filter(fn ($s) => isset($attributionBySendId[$s['id']]))
            ->map(fn ($s) => array_merge($s, $attributionBySendId[$s['id']]))
            ->values();

        // 4b) Manual "proof submitted / in review" stage — for manual markets,
        //     clients pay offline and upload proof before activation. Credit each
        //     submission to its most recent send (last-touch), same as conversions.
        $submissions = $clientIds->isEmpty()
            ? collect()
            : \App\Models\PaymentManualSubmission::query()
                ->whereIn('client_id', $clientIds->all())
                ->whereBetween('created_at', [$from, $to->copy()->addDays($windowDays)])
                ->get(['payment_id', 'client_id', 'created_at'])
                ->map(fn ($s) => [
                    'payment_id' => (int) $s->payment_id,
                    'client_id' => (int) $s->client_id,
                    'amount' => 0.0,
                    'currency' => 'USD',
                    'completed_at' => $s->created_at,
                    'client_first_paid_at' => null,
                ])
                ->sortBy('completed_at')
                ->values();
        $submittedCount = count($this->attribute($sends, $submissions, $windowDays));

        // 5) Roll everything up.
        $sentCount = $sends->count();
        $openedCount = $sends->filter(fn ($s) => $s['payment_id'] && $openedSet->has((int) $s['payment_id']))->count();
        $directCount = $attributed->where('direct', true)->count();
        $assistedCount = $attributed->where('direct', false)->count();
        $convertedCount = $attributed->count();
        $attributedUsd = round((float) $attributed->sum('usd'), 2);
        $hours = $attributed->pluck('hours_to_convert')->filter(fn ($h) => $h !== null)->sort()->values();

        return [
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'window_days' => $windowDays,
            'platform_id' => $platformId,
            'currency' => $targetCurrency,
            'capped' => $capped,
            'funnel' => [
                'sent' => $sentCount,
                'opened' => $openedCount,
                'submitted' => $submittedCount,
                'converted' => $convertedCount,
                'open_rate' => $sentCount > 0 ? round($openedCount / $sentCount * 100, 1) : 0.0,
                'submitted_rate' => $sentCount > 0 ? round($submittedCount / $sentCount * 100, 1) : 0.0,
                'conversion_rate' => $sentCount > 0 ? round($convertedCount / $sentCount * 100, 1) : 0.0,
                'direct' => $directCount,
                'assisted' => $assistedCount,
            ],
            'attributed_revenue_usd' => $attributedUsd,
            'time_to_convert' => [
                'median_hours' => $this->median($hours),
                'avg_hours' => $hours->isNotEmpty() ? round((float) $hours->avg(), 1) : null,
                'count' => $hours->count(),
            ],
            'new_vs_existing' => [
                'new' => $attributed->where('is_new', true)->count(),
                'existing' => $attributed->where('is_new', false)->count(),
            ],
            'by_flow' => $this->byFlow($sends, $openedSet, $attributed),
            'by_market' => $this->byMarket($sends, $attributed, $platformId),
            'payments' => $this->paymentRollup($from, $to, $platformId, $targetCurrency),
        ];
    }

    /**
     * Per-message drill: one paginated row per lifecycle send with its content,
     * opened flag, conversion outcome (direct/assisted/none), value, time-to-
     * convert and new/existing — the same attribution the overview aggregates.
     *
     * @param array{from?:string,to?:string,platform_id?:int|null,window_days?:int|null,flow?:string,outcome?:string,search?:string} $filters
     */
    public function messages(array $filters, int $page = 1, int $perPage = 25): array
    {
        [$from, $to, $platformId, $windowDays] = $this->resolveFilters($filters);

        $sends = $this->loadSends($from, $to, $platformId);
        $sendPaymentIds = $sends->pluck('payment_id')->filter()->unique()->values();
        $clientIds = $sends->pluck('client_id')->filter()->unique()->values();

        $openedSet = $sendPaymentIds->isEmpty()
            ? collect()
            : BillingProxySession::query()
                ->whereIn('payment_id', $sendPaymentIds->all())
                ->whereNotNull('opened_at')
                ->pluck('payment_id')->map(fn ($id) => (int) $id)->unique()->flip();

        $conversions = $clientIds->isEmpty()
            ? collect()
            : $this->loadConversions($clientIds, $from, $to->copy()->addDays($windowDays), $platformId);
        $attribution = $this->attribute($sends, $conversions, $windowDays);

        $names = $clientIds->isEmpty() ? collect() : \App\Models\Client::query()->whereIn('id', $clientIds->all())->pluck('name', 'id');

        $flowFilter = trim((string) ($filters['flow'] ?? ''));
        $outcome = trim((string) ($filters['outcome'] ?? '')); // opened | converted | not_converted
        $search = strtolower(trim((string) ($filters['search'] ?? '')));

        $rows = $sends
            ->map(function ($s) use ($openedSet, $attribution, $names) {
                $attr = $attribution[$s['id']] ?? null;
                return [
                    'id' => $s['id'],
                    'sent_at' => $s['sent_at']->toIso8601String(),
                    'client_id' => $s['client_id'],
                    'client_name' => (string) ($names[$s['client_id']] ?? ('Client #' . $s['client_id'])),
                    'flow' => $s['flow'],
                    'source' => $s['source'],
                    'body' => $s['body'],
                    'opened' => (bool) ($s['payment_id'] && $openedSet->has((int) $s['payment_id'])),
                    'converted' => $attr !== null,
                    'conversion_type' => $attr === null ? null : ($attr['direct'] ? 'direct' : 'assisted'),
                    'value_usd' => $attr['usd'] ?? null,
                    'hours_to_convert' => $attr['hours_to_convert'] ?? null,
                    'is_new' => $attr['is_new'] ?? null,
                ];
            })
            ->filter(function ($row) use ($flowFilter, $outcome, $search) {
                if ($flowFilter !== '' && $row['flow'] !== $flowFilter) {
                    return false;
                }
                if ($outcome === 'opened' && !$row['opened']) {
                    return false;
                }
                if ($outcome === 'converted' && !$row['converted']) {
                    return false;
                }
                if ($outcome === 'not_converted' && $row['converted']) {
                    return false;
                }
                if ($search !== '' && !str_contains(strtolower($row['client_name']), $search) && !str_contains(strtolower((string) $row['body']), $search)) {
                    return false;
                }

                return true;
            })
            ->sortByDesc('sent_at')
            ->values();

        $total = $rows->count();
        $perPage = max(5, min(100, $perPage));
        $page = max(1, $page);

        return [
            'data' => $rows->forPage($page, $perPage)->values()->all(),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => max(1, (int) ceil($total / $perPage)),
            'window_days' => $windowDays,
        ];
    }

    /**
     * Last-touch attribution map: send_id => outcome. Each conversion credits the
     * client's most recent qualifying send; one conversion per send.
     */
    private function attribute(Collection $sends, Collection $conversions, int $windowDays): array
    {
        $sendsByClient = $sends->groupBy('client_id')->map(
            fn (Collection $rows) => $rows->sortBy('sent_at')->values()
        );
        $creditedSendIds = [];
        $map = [];

        foreach ($conversions as $conversion) {
            $clientSends = $sendsByClient->get($conversion['client_id']);
            if (!$clientSends) {
                continue;
            }

            $windowStart = Carbon::parse($conversion['completed_at'])->subDays($windowDays);
            $match = null;
            foreach ($clientSends as $send) {
                $sentAt = $send['sent_at'];
                if ($sentAt->lte($conversion['completed_at']) && $sentAt->gte($windowStart) && !isset($creditedSendIds[$send['id']])) {
                    $match = $send; // latest qualifying send wins (asc list)
                }
            }
            if (!$match) {
                continue;
            }

            $creditedSendIds[$match['id']] = true;
            $map[$match['id']] = [
                'direct' => (bool) ($match['payment_id'] && (int) $match['payment_id'] === (int) $conversion['payment_id']),
                'usd' => $this->toUsd((float) $conversion['amount'], (string) $conversion['currency'], $conversion['completed_at']),
                'hours_to_convert' => max(0, $match['sent_at']->diffInMinutes($conversion['completed_at']) / 60),
                'is_new' => $conversion['client_first_paid_at'] === null
                    || Carbon::parse($conversion['client_first_paid_at'])->gte($match['sent_at']),
            ];
        }

        return $map;
    }

    /** @return array{0:Carbon,1:Carbon,2:?int,3:int} */
    private function resolveFilters(array $filters): array
    {
        $to = isset($filters['to']) && $filters['to'] ? Carbon::parse($filters['to'])->endOfDay() : now();
        $from = isset($filters['from']) && $filters['from'] ? Carbon::parse($filters['from'])->startOfDay() : $to->copy()->subDays(30)->startOfDay();
        $platformId = !empty($filters['platform_id']) ? (int) $filters['platform_id'] : null;
        $windowDays = isset($filters['window_days']) && $filters['window_days']
            ? max(1, min(90, (int) $filters['window_days']))
            : $this->defaultWindowDays();

        return [$from, $to, $platformId, $windowDays];
    }

    private function loadSends(Carbon $from, Carbon $to, ?int $platformId): Collection
    {
        return TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('event_type', LifecycleSmsService::TIMELINE_EVENT_TYPE)
            ->whereBetween('created_at', [$from, $to])
            ->when($platformId, fn (Builder $q) => $q->where('platform_id', $platformId))
            ->orderBy('created_at')
            ->limit(self::MAX_SENDS)
            ->get(['id', 'platform_id', 'entity_id', 'content', 'created_at'])
            ->map(function (TimelineEvent $event) {
                $content = is_array($event->content) ? $event->content : [];
                if (($content['status'] ?? null) !== 'sent') {
                    return null;
                }

                return [
                    'id' => (int) $event->id,
                    'platform_id' => $event->platform_id ? (int) $event->platform_id : null,
                    'client_id' => (int) $event->entity_id,
                    'flow' => (string) ($content['flow'] ?? 'unknown'),
                    'payment_id' => isset($content['payment_id']) ? (int) $content['payment_id'] : null,
                    'body' => (string) ($content['body'] ?? ''),
                    'source' => (string) ($content['source'] ?? 'automated'),
                    'sent_at' => $event->created_at,
                ];
            })
            ->filter()
            ->values();
    }

    private function loadConversions(Collection $clientIds, Carbon $from, Carbon $toPlusWindow, ?int $platformId): Collection
    {
        // First-ever successful payment per client, to classify new vs existing.
        $firstPaidByClient = Payment::query()
            ->whereIn('client_id', $clientIds->all())
            ->whereIn('status', Payment::SUCCESSFUL_STATUSES)
            ->businessVisible()
            ->selectRaw('client_id, MIN(COALESCE(completed_at, created_at)) as first_paid_at')
            ->groupBy('client_id')
            ->pluck('first_paid_at', 'client_id');

        return Payment::query()
            ->whereIn('client_id', $clientIds->all())
            ->where('status', 'completed')
            ->businessVisible()
            ->when($platformId, fn (Builder $q) => $q->where('platform_id', $platformId))
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $toPlusWindow])
            ->get(['id', 'client_id', 'amount', 'currency', 'completed_at'])
            ->map(fn (Payment $payment) => [
                'payment_id' => (int) $payment->id,
                'client_id' => (int) $payment->client_id,
                'amount' => (float) $payment->amount,
                'currency' => (string) ($payment->currency ?: 'KES'),
                'completed_at' => $payment->completed_at,
                'client_first_paid_at' => $firstPaidByClient[$payment->client_id] ?? null,
            ])
            ->sortBy('completed_at')
            ->values();
    }

    private function byFlow(Collection $sends, Collection $openedSet, Collection $attributed): array
    {
        return collect(self::FLOWS)->map(function (string $flow) use ($sends, $openedSet, $attributed) {
            $flowSends = $sends->where('flow', $flow);
            $flowConv = $attributed->where('flow', $flow);
            $sent = $flowSends->count();

            return [
                'flow' => $flow,
                'sent' => $sent,
                'opened' => $flowSends->filter(fn ($s) => $s['payment_id'] && $openedSet->has((int) $s['payment_id']))->count(),
                'direct' => $flowConv->where('direct', true)->count(),
                'assisted' => $flowConv->where('direct', false)->count(),
                'converted' => $flowConv->count(),
                'conversion_rate' => $sent > 0 ? round($flowConv->count() / $sent * 100, 1) : 0.0,
                'revenue_usd' => round((float) $flowConv->sum('usd'), 2),
            ];
        })->values()->all();
    }

    private function byMarket(Collection $sends, Collection $attributed, ?int $platformId): array
    {
        $platformIds = $sends->pluck('platform_id')->filter()->unique();
        if ($platformIds->isEmpty()) {
            return [];
        }

        $names = Platform::query()->whereIn('id', $platformIds->all())->pluck('name', 'id');

        return $platformIds->map(function ($pid) use ($sends, $attributed, $names) {
            $marketSends = $sends->where('platform_id', $pid);
            $marketConv = $attributed->where('platform_id', $pid);
            $sent = $marketSends->count();

            return [
                'platform_id' => (int) $pid,
                'platform_name' => (string) ($names[$pid] ?? ('Market #' . $pid)),
                'sent' => $sent,
                'converted' => $marketConv->count(),
                'conversion_rate' => $sent > 0 ? round($marketConv->count() / $sent * 100, 1) : 0.0,
                'revenue_usd' => round((float) $marketConv->sum('usd'), 2),
            ];
        })->sortByDesc('sent')->values()->all();
    }

    /** Lifecycle-initiated payments grouped by status, with cumulative USD value. */
    private function paymentRollup(Carbon $from, Carbon $to, ?int $platformId, string $targetCurrency): array
    {
        $rows = Payment::query()
            ->where('raw_payload->source', 'crm_lifecycle')
            ->whereBetween('created_at', [$from, $to])
            ->when($platformId, fn (Builder $q) => $q->where('platform_id', $platformId))
            ->get(['id', 'status', 'amount', 'currency', 'completed_at', 'created_at']);

        $buckets = [
            'completed' => ['label' => 'Successful', 'statuses' => ['completed', 'activated']],
            'pending' => ['label' => 'Pending', 'statuses' => ['initiated', 'pending']],
            'failed' => ['label' => 'Failed', 'statuses' => ['failed']],
        ];

        $out = [];
        foreach ($buckets as $key => $bucket) {
            $group = $rows->whereIn('status', $bucket['statuses']);
            $usd = $group->sum(fn (Payment $p) => $this->toUsd(
                (float) $p->amount,
                (string) ($p->currency ?: 'KES'),
                $p->completed_at ?: $p->created_at
            ));
            $out[$key] = [
                'label' => $bucket['label'],
                'count' => $group->count(),
                'value_usd' => round((float) $usd, 2),
            ];
        }

        $out['total'] = [
            'label' => 'All lifecycle payments',
            'count' => $rows->count(),
            'value_usd' => round((float) array_sum(array_column($out, 'value_usd')), 2),
        ];

        return $out;
    }

    private function toUsd(float $amount, string $currency, $eventDate = null): float
    {
        if ($amount <= 0) {
            return 0.0;
        }

        try {
            $normalized = $this->reportingCurrency->normalizeBreakdown(
                [strtoupper($currency) => $amount],
                $eventDate instanceof Carbon ? $eventDate : ($eventDate ? Carbon::parse($eventDate) : null),
                'USD'
            );

            // normalized_total is null when a rate is missing; fall back to the
            // raw amount so a single-currency view still totals (approximate).
            $total = $normalized['normalized_total'];

            return $total !== null ? (float) $total : $amount;
        } catch (\Throwable) {
            return $amount;
        }
    }

    private function median(Collection $sorted): ?float
    {
        $n = $sorted->count();
        if ($n === 0) {
            return null;
        }
        $mid = intdiv($n, 2);

        return round(
            $n % 2 ? (float) $sorted[$mid] : (((float) $sorted[$mid - 1] + (float) $sorted[$mid]) / 2),
            1
        );
    }
}
