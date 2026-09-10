<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ContactUnlockEvent;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\VisitorContactUnlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Row-level backing for the Demand tab's headline numbers.
 *
 * Each KPI on the Demand tab is a count with no way to see what it counted. This service
 * answers "which rows?" for the three that matter commercially, and it reproduces the KPI's
 * own filters exactly — same date column, same statuses, same platform scope — so the
 * drill-down total always ties back to the card the reader clicked.
 */
class ContactUnlockDemandDetailService
{
    public const METRIC_RENEWED = 'renewed_after_demand';
    public const METRIC_SINGLE = 'single_profile_purchases';
    public const METRIC_FULL_ACCESS = 'full_access_purchases';

    public const METRICS = [self::METRIC_RENEWED, self::METRIC_SINGLE, self::METRIC_FULL_ACCESS];

    /** Matches ContactUnlockPulseService::renewedAfterDemand(). Changing one without the other breaks the tie-back. */
    private const RENEWAL_WINDOW_DAYS = 14;

    /** Drill-downs are read in a drawer, not exported wholesale — cap the working set so a wide range cannot OOM the request. */
    private const MAX_ROWS = 1000;

    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService,
        private readonly ContactUnlockPulseService $pulseService
    ) {}

    public function detail(
        string $metric,
        int|array|null $platformIds = null,
        string $range = 'today',
        ?string $timezone = null,
        ?string $targetCurrency = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        [$from, $to] = $this->pulseService->window($range, $timezone, $fromDate, $toDate);
        $target = $this->reportingCurrencyService->resolveTargetCurrency($targetCurrency);

        $payload = match ($metric) {
            self::METRIC_RENEWED => $this->renewedAfterDemand($platformIds, $from, $to, $target),
            self::METRIC_SINGLE => $this->scopePurchases(VisitorContactUnlock::SCOPE_SINGLE_PROFILE, $platformIds, $from, $to, $target),
            self::METRIC_FULL_ACCESS => $this->scopePurchases(VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES, $platformIds, $from, $to, $target),
            default => ['rows' => [], 'totals' => []],
        };

        return array_merge([
            'metric' => $metric,
            'range' => [
                'key' => $range,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'normalized_currency' => $target,
            'row_limit' => self::MAX_ROWS,
        ], $payload);
    }

    /**
     * Advertisers whose profile drew paid visitor demand and who then paid for a subscription
     * within the renewal window. One row per advertiser: how much demand preceded the renewal,
     * how long it took, and what the renewal did to the expiry date.
     */
    private function renewedAfterDemand(int|array|null $platformIds, CarbonImmutable $from, CarbonImmutable $to, string $target): array
    {
        $unlocks = $this->paidUnlocksWithClient($platformIds, $from, $to);

        if ($unlocks->isEmpty()) {
            return ['rows' => [], 'totals' => $this->emptyRenewalTotals($target), 'truncated' => false];
        }

        $byClient = $unlocks->groupBy(fn ($unlock) => (int) $unlock->client_id);
        $clientIds = $byClient->keys()->all();
        $truncated = count($clientIds) > self::MAX_ROWS;
        if ($truncated) {
            $clientIds = array_slice($clientIds, 0, self::MAX_ROWS);
        }

        $renewalPayments = $this->subscriptionPaymentsFor($clientIds, $from, $to);
        $deals = $this->dealsFor($clientIds);
        $clients = Client::query()
            ->whereIn('id', $clientIds)
            ->with('platform:id,name,country,currency_code')
            ->get(['id', 'platform_id', 'name', 'city', 'wp_post_id', 'wp_profile_permalink', 'profile_status', 'escort_expire', 'assigned_to']);

        $candidates = [];
        foreach ($clientIds as $clientId) {
            $clientUnlocks = $byClient->get($clientId, collect())->sortBy('created_at')->values();
            $firstDemand = CarbonImmutable::parse((string) $clientUnlocks->first()->created_at);
            $renewal = $this->firstRenewalAfter($renewalPayments->get($clientId, collect()), $firstDemand);

            if (! $renewal) {
                continue;
            }

            $candidates[] = [
                'client_id' => $clientId,
                'first_demand_at' => $firstDemand,
                'renewal' => $renewal['payment'],
                'renewed_at' => $renewal['paid_at'],
                'unlocks' => $clientUnlocks,
            ];
        }

        $rateMap = $this->rateMap(
            collect($candidates)->groupBy(fn ($row) => $this->currencyOf($row['renewal']))
                ->map(fn ($group) => (float) $group->sum(fn ($row) => (float) $row['renewal']->amount))
                ->all(),
            $to,
            $target
        );

        $rows = [];
        foreach ($candidates as $candidate) {
            $clientId = $candidate['client_id'];
            $client = $clients->firstWhere('id', $clientId);
            $renewedAt = $candidate['renewed_at'];
            $firstDemand = $candidate['first_demand_at'];
            $clientDeals = $deals->get($clientId, collect());
            $currency = $this->currencyOf($candidate['renewal']);
            $amount = (float) $candidate['renewal']->amount;

            $renewalDeal = $clientDeals->first(fn ($deal) => (int) $deal->payment_id === (int) $candidate['renewal']->id);
            $currentExpiry = $this->currentExpiry($renewalDeal, $clientDeals, $client);
            $previousExpiry = $this->previousExpiry($clientDeals, $renewalDeal, $renewedAt);

            // Demand that actually preceded the money. Unlocks after the renewal are a
            // different story and would flatter the metric if they were counted here.
            $unlocksBefore = $candidate['unlocks']->filter(fn ($unlock) => CarbonImmutable::parse((string) $unlock->created_at)->lessThanOrEqualTo($renewedAt));

            $rows[] = [
                'client_id' => $clientId,
                'client_name' => (string) ($client?->name ?: 'Unknown profile'),
                'profile_url' => (string) ($client?->wp_profile_permalink ?: ''),
                'wp_post_id' => (int) ($client?->wp_post_id ?: 0),
                'city' => (string) ($client?->city ?: ''),
                'profile_status' => (string) ($client?->profile_status ?: ''),
                'market' => (string) ($client?->platform?->name ?: ''),
                'market_country' => (string) ($client?->platform?->country ?: ''),
                'platform_id' => (int) ($client?->platform_id ?: 0),
                'unlocks_before_renewal' => $unlocksBefore->count(),
                'unlocks_in_window' => $candidate['unlocks']->count(),
                'first_demand_at' => $firstDemand->toIso8601String(),
                'renewed_at' => $renewedAt->toIso8601String(),
                'days_to_renewal' => round($firstDemand->floatDiffInDays($renewedAt), 1),
                'amount' => $amount,
                'currency' => $currency,
                'amount_normalized' => $this->convert($amount, $currency, $rateMap['rates'], $target),
                'payment_reference' => (string) ($candidate['renewal']->reference_number ?: ''),
                'payment_id' => (int) $candidate['renewal']->id,
                'previous_expiry' => $previousExpiry?->toIso8601String(),
                'current_expiry' => $currentExpiry?->toIso8601String(),
                // Positive = renewed while still live. Negative = the profile had already lapsed.
                'days_before_expiry' => $previousExpiry ? round($renewedAt->floatDiffInDays($previousExpiry, false), 1) : null,
                'days_extended' => $previousExpiry && $currentExpiry ? round($previousExpiry->floatDiffInDays($currentExpiry, false), 1) : null,
                'expiry_source' => $renewalDeal ? 'deal' : ($currentExpiry ? 'profile' : 'none'),
            ];
        }

        usort($rows, fn ($a, $b) => strcmp((string) $b['renewed_at'], (string) $a['renewed_at']));

        return [
            'rows' => $rows,
            'truncated' => $truncated,
            'totals' => [
                'clients' => count($rows),
                'unlocks_before_renewal' => array_sum(array_column($rows, 'unlocks_before_renewal')),
                'revenue_normalized' => $this->sumNormalized($rows),
                'normalized_currency' => $target,
                'median_days_to_renewal' => $this->median(array_column($rows, 'days_to_renewal')),
                'normalization_meta' => $rateMap['meta'],
            ],
        ];
    }

    /**
     * One row per paid unlock of the given scope: which anonymous visitor paid, which
     * advertiser they were buying access to, and what they did with the access afterwards.
     */
    private function scopePurchases(string $scope, int|array|null $platformIds, CarbonImmutable $from, CarbonImmutable $to, string $target): array
    {
        $query = Payment::query()
            ->contactUnlockRevenue()
            ->whereIn('status', Payment::SUCCESSFUL_STATUSES)
            ->whereBetween(DB::raw('COALESCE(completed_at, updated_at, created_at)'), [$from, $to])
            ->whereHas('contactUnlock', fn ($unlockQuery) => $unlockQuery->where('scope', $scope));
        $this->applyPlatformScope($query, $platformIds);

        $total = (int) (clone $query)->count();

        $payments = $query
            ->with([
                'contactUnlock:id,payment_id,platform_id,client_id,wp_post_id,scope,status,visitor_phone_hash,visitor_phone_masked,visitor_email_masked,session_token_hash,gross_amount,credit_amount,amount_due,starts_at,expires_at,last_revealed_at,reveal_count,created_at',
                'contactUnlock.client:id,name,city,wp_post_id,wp_profile_permalink,profile_status,escort_expire',
                'contactUnlock.platform:id,name,country,currency_code',
            ])
            ->orderByRaw('COALESCE(completed_at, updated_at, created_at) desc')
            ->limit(self::MAX_ROWS)
            ->get(['id', 'platform_id', 'client_id', 'amount', 'currency', 'status', 'reference_number', 'provider_key', 'completed_at', 'updated_at', 'created_at']);

        if ($payments->isEmpty()) {
            return ['rows' => [], 'total' => 0, 'truncated' => false, 'totals' => $this->emptyPurchaseTotals($target)];
        }

        $unlockIds = $payments->pluck('contactUnlock.id')->filter()->map(fn ($id) => (int) $id)->all();
        $checkouts = $this->checkoutContext($unlockIds);
        $reveals = $this->revealedProfiles($unlockIds);
        $visitorPurchases = $this->visitorPurchaseCounts($platformIds, $from, $to);

        $rateMap = $this->rateMap(
            $payments->groupBy(fn ($payment) => $this->currencyOf($payment))
                ->map(fn ($group) => (float) $group->sum('amount'))
                ->all(),
            $to,
            $target
        );

        $rows = $payments->map(function (Payment $payment) use ($checkouts, $reveals, $visitorPurchases, $rateMap, $target) {
            $unlock = $payment->contactUnlock;
            $unlockId = (int) ($unlock?->id ?? 0);
            $currency = $this->currencyOf($payment);
            $amount = (float) $payment->amount;
            $phoneHash = (string) ($unlock?->visitor_phone_hash ?? '');
            $revealed = $reveals->get($unlockId, collect());

            return [
                'unlock_id' => $unlockId,
                'payment_id' => (int) $payment->id,
                'purchased_at' => $this->paidAt($payment)->toIso8601String(),
                'visitor' => [
                    // Visitors are anonymous by design — these are the only stable handles we hold.
                    'phone_masked' => (string) ($unlock?->visitor_phone_masked ?: ''),
                    'email_masked' => (string) ($unlock?->visitor_email_masked ?: ''),
                    'phone_hash' => $phoneHash,
                    'reference' => $phoneHash !== '' ? 'V-'.strtoupper(substr($phoneHash, 0, 8)) : '',
                    'session_reference' => $unlock?->session_token_hash ? 'S-'.strtoupper(substr((string) $unlock->session_token_hash, 0, 8)) : '',
                    'purchases_in_window' => (int) ($visitorPurchases[$phoneHash] ?? 0),
                    'is_repeat_buyer' => (int) ($visitorPurchases[$phoneHash] ?? 0) > 1,
                ],
                'client' => [
                    'id' => (int) ($unlock?->client_id ?: 0),
                    'name' => (string) ($unlock?->client?->name ?: ''),
                    'city' => (string) ($unlock?->client?->city ?: ''),
                    'profile_url' => (string) ($unlock?->client?->wp_profile_permalink ?: ''),
                    'wp_post_id' => (int) ($unlock?->wp_post_id ?: $unlock?->client?->wp_post_id ?: 0),
                    'profile_status' => (string) ($unlock?->client?->profile_status ?: ''),
                ],
                'market' => (string) ($unlock?->platform?->name ?: ''),
                'market_country' => (string) ($unlock?->platform?->country ?: ''),
                'platform_id' => (int) ($unlock?->platform_id ?: $payment->platform_id ?: 0),
                'amount' => $amount,
                'currency' => $currency,
                'amount_normalized' => $this->convert($amount, $currency, $rateMap['rates'], $target),
                'gross_amount' => (float) ($unlock?->gross_amount ?? $amount),
                'credit_amount' => (float) ($unlock?->credit_amount ?? 0),
                'payment_reference' => (string) ($payment->reference_number ?: ''),
                'provider_key' => (string) ($payment->provider_key ?: ''),
                'unlock_status' => (string) ($unlock?->status ?: ''),
                'unlock_expires_at' => $unlock?->expires_at?->toIso8601String(),
                'reveal_count' => (int) ($unlock?->reveal_count ?? 0),
                'last_revealed_at' => $unlock?->last_revealed_at?->toIso8601String(),
                // For market-wide access the purchase has no single advertiser attached; the
                // reveal trail is the only record of who the visitor actually contacted.
                'revealed_profiles' => $revealed->map(fn ($row) => [
                    'client_id' => (int) $row->client_id,
                    'name' => (string) ($row->client_name ?: 'Unknown profile'),
                    'reveals' => (int) $row->reveal_count,
                    'last_revealed_at' => $row->last_revealed_at ? CarbonImmutable::parse((string) $row->last_revealed_at)->toIso8601String() : null,
                ])->values()->all(),
                'revealed_profile_count' => $revealed->count(),
                'traffic_source' => (string) ($checkouts[$unlockId]['traffic_source'] ?? ''),
                'referrer_host' => (string) ($checkouts[$unlockId]['referrer_host'] ?? ''),
                'local_hour' => $checkouts[$unlockId]['local_hour'] ?? null,
            ];
        })->values()->all();

        return [
            'rows' => $rows,
            'total' => $total,
            'truncated' => $total > count($rows),
            'totals' => [
                'purchases' => $total,
                'shown' => count($rows),
                'revenue_normalized' => $this->sumNormalized($rows),
                'normalized_currency' => $target,
                'distinct_visitors' => collect($rows)->pluck('visitor.phone_hash')->filter()->unique()->count(),
                'distinct_clients' => collect($rows)->pluck('client.id')->filter()->unique()->count(),
                'repeat_buyers' => collect($rows)->where('visitor.is_repeat_buyer', true)->count(),
                'normalization_meta' => $rateMap['meta'],
            ],
        ];
    }

    private function paidUnlocksWithClient(int|array|null $platformIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $query = VisitorContactUnlock::query()
            ->whereNotNull('client_id')
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('payment', fn ($paymentQuery) => $paymentQuery->whereIn('status', Payment::SUCCESSFUL_STATUSES))
            ->orderBy('created_at');
        $this->applyPlatformScope($query, $platformIds);

        return $query->limit(self::MAX_ROWS * 5)->get(['id', 'client_id', 'platform_id', 'scope', 'created_at']);
    }

    /** @return Collection<int, Collection<int, Payment>> keyed by client id */
    private function subscriptionPaymentsFor(array $clientIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        if ($clientIds === []) {
            return collect();
        }

        return Payment::query()
            ->subscriptionRevenue()
            ->whereIn('client_id', $clientIds)
            ->whereIn('status', Payment::SUCCESSFUL_STATUSES)
            ->whereBetween(DB::raw('COALESCE(completed_at, updated_at, created_at)'), [$from, $to->addDays(self::RENEWAL_WINDOW_DAYS)])
            ->orderByRaw('COALESCE(completed_at, updated_at, created_at) asc')
            ->get(['id', 'client_id', 'platform_id', 'deal_id', 'amount', 'currency', 'reference_number', 'completed_at', 'updated_at', 'created_at'])
            ->groupBy(fn (Payment $payment) => (int) $payment->client_id);
    }

    /** @return Collection<int, Collection<int, Deal>> keyed by client id */
    private function dealsFor(array $clientIds): Collection
    {
        if ($clientIds === []) {
            return collect();
        }

        return Deal::query()
            ->whereIn('client_id', $clientIds)
            ->orderByRaw('COALESCE(activated_at, created_at) asc')
            ->get(['id', 'client_id', 'payment_id', 'plan_type', 'duration', 'status', 'activated_at', 'expires_at', 'created_at'])
            ->groupBy(fn (Deal $deal) => (int) $deal->client_id);
    }

    /** @return array{payment: Payment, paid_at: CarbonImmutable}|null */
    private function firstRenewalAfter(Collection $payments, CarbonImmutable $firstDemand): ?array
    {
        $deadline = $firstDemand->addDays(self::RENEWAL_WINDOW_DAYS);

        foreach ($payments as $payment) {
            $paidAt = $this->paidAt($payment);
            if ($paidAt->greaterThanOrEqualTo($firstDemand) && $paidAt->lessThanOrEqualTo($deadline)) {
                return ['payment' => $payment, 'paid_at' => $paidAt];
            }
        }

        return null;
    }

    private function currentExpiry(?Deal $renewalDeal, Collection $deals, ?Client $client): ?CarbonImmutable
    {
        if ($renewalDeal?->expires_at) {
            return CarbonImmutable::parse((string) $renewalDeal->expires_at);
        }

        $latest = $deals->filter(fn (Deal $deal) => $deal->expires_at !== null)
            ->sortByDesc(fn (Deal $deal) => (string) $deal->expires_at)
            ->first();

        if ($latest?->expires_at) {
            return CarbonImmutable::parse((string) $latest->expires_at);
        }

        // WordPress remains the source of truth for expiry; escort_expire is its unix stamp.
        $stamp = (int) ($client?->escort_expire ?? 0);

        return $stamp > 0 ? CarbonImmutable::createFromTimestamp($stamp) : null;
    }

    private function previousExpiry(Collection $deals, ?Deal $renewalDeal, CarbonImmutable $renewedAt): ?CarbonImmutable
    {
        $prior = $deals
            ->filter(fn (Deal $deal) => $deal->expires_at !== null)
            ->filter(fn (Deal $deal) => $renewalDeal === null || (int) $deal->id !== (int) $renewalDeal->id)
            ->filter(fn (Deal $deal) => CarbonImmutable::parse((string) ($deal->activated_at ?: $deal->created_at))->lessThan($renewedAt))
            ->sortByDesc(fn (Deal $deal) => (string) $deal->expires_at)
            ->first();

        return $prior?->expires_at ? CarbonImmutable::parse((string) $prior->expires_at) : null;
    }

    /** Traffic source and hour for each unlock, taken from the checkout it started. */
    private function checkoutContext(array $unlockIds): array
    {
        if ($unlockIds === []) {
            return [];
        }

        return ContactUnlockEvent::query()
            ->whereIn('visitor_contact_unlock_id', $unlockIds)
            ->where('event_type', ContactUnlockEvent::TYPE_CHECKOUT_START)
            ->orderBy('occurred_at')
            ->get(['visitor_contact_unlock_id', 'traffic_source', 'referrer_host', 'local_hour'])
            ->groupBy('visitor_contact_unlock_id')
            ->map(fn ($group) => [
                'traffic_source' => (string) ($group->first()->traffic_source ?: ''),
                'referrer_host' => (string) ($group->first()->referrer_host ?: ''),
                'local_hour' => $group->first()->local_hour !== null ? (int) $group->first()->local_hour : null,
            ])
            ->all();
    }

    /** @return Collection<int, Collection> reveal rows keyed by unlock id */
    private function revealedProfiles(array $unlockIds): Collection
    {
        if ($unlockIds === []) {
            return collect();
        }

        return ContactUnlockEvent::query()
            ->leftJoin('clients', 'clients.id', '=', 'contact_unlock_events.client_id')
            ->whereIn('contact_unlock_events.visitor_contact_unlock_id', $unlockIds)
            ->where('contact_unlock_events.event_type', ContactUnlockEvent::TYPE_UNLOCK_REVEAL)
            ->whereNotNull('contact_unlock_events.client_id')
            ->groupBy('contact_unlock_events.visitor_contact_unlock_id', 'contact_unlock_events.client_id', 'clients.name')
            ->orderByDesc('reveal_count')
            ->get([
                'contact_unlock_events.visitor_contact_unlock_id as unlock_id',
                'contact_unlock_events.client_id as client_id',
                'clients.name as client_name',
                DB::raw('COUNT(*) as reveal_count'),
                DB::raw('MAX(contact_unlock_events.occurred_at) as last_revealed_at'),
            ])
            ->groupBy(fn ($row) => (int) $row->unlock_id);
    }

    /** How many paid unlocks each visitor made in the window — the repeat-buyer signal. */
    private function visitorPurchaseCounts(int|array|null $platformIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = VisitorContactUnlock::query()
            ->join('payments', 'payments.id', '=', 'visitor_contact_unlocks.payment_id')
            ->whereIn('payments.status', Payment::SUCCESSFUL_STATUSES)
            ->whereBetween(DB::raw('COALESCE(payments.completed_at, payments.updated_at, payments.created_at)'), [$from, $to])
            ->groupBy('visitor_contact_unlocks.visitor_phone_hash');

        if (is_int($platformIds)) {
            $query->where('visitor_contact_unlocks.platform_id', $platformIds);
        } elseif (is_array($platformIds)) {
            if ($platformIds === []) {
                return [];
            }
            $query->whereIn('visitor_contact_unlocks.platform_id', $platformIds);
        }

        return $query
            ->get(['visitor_contact_unlocks.visitor_phone_hash as phone_hash', DB::raw('COUNT(*) as aggregate_count')])
            ->mapWithKeys(fn ($row) => [(string) $row->phone_hash => (int) $row->aggregate_count])
            ->all();
    }

    /**
     * One FX resolution pass for the whole result set. Per-row conversion then reuses the
     * resolved rate, so a 500-row drawer does not become 500 rate lookups.
     *
     * @return array{rates: array<string, float>, meta: array}
     */
    private function rateMap(array $currencyTotals, CarbonImmutable $asOf, string $target): array
    {
        $currencyTotals = array_filter($currencyTotals, fn ($amount) => $amount !== null);

        if ($currencyTotals === []) {
            return ['rates' => [], 'meta' => []];
        }

        $normalized = $this->reportingCurrencyService->normalizeBreakdown($currencyTotals, $asOf, $target);
        $rates = [];
        foreach ((array) data_get($normalized, 'normalization_meta.rows', []) as $row) {
            $currency = (string) data_get($row, 'source_currency', '');
            $rate = data_get($row, 'rate');
            if ($currency !== '' && $rate !== null) {
                $rates[$currency] = (float) $rate;
            }
        }

        return ['rates' => $rates, 'meta' => $normalized['normalization_meta'] ?? []];
    }

    private function convert(float $amount, string $currency, array $rates, string $target): ?float
    {
        if ($currency === $target) {
            return round($amount, 2);
        }

        return isset($rates[$currency]) ? round($amount * $rates[$currency], 2) : null;
    }

    private function sumNormalized(array $rows): ?float
    {
        $total = 0.0;
        foreach ($rows as $row) {
            if ($row['amount_normalized'] === null) {
                return null;
            }
            $total += (float) $row['amount_normalized'];
        }

        return round($total, 2);
    }

    private function median(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($value) => $value !== null));
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? round((($values[$middle - 1] + $values[$middle]) / 2), 1)
            : round($values[$middle], 1);
    }

    private function paidAt(Payment $payment): CarbonImmutable
    {
        return CarbonImmutable::parse((string) ($payment->completed_at ?: $payment->updated_at ?: $payment->created_at));
    }

    private function currencyOf(Payment $payment): string
    {
        return strtoupper((string) ($payment->currency ?: 'USD'));
    }

    private function emptyRenewalTotals(string $target): array
    {
        return [
            'clients' => 0,
            'unlocks_before_renewal' => 0,
            'revenue_normalized' => 0.0,
            'normalized_currency' => $target,
            'median_days_to_renewal' => null,
            'normalization_meta' => [],
        ];
    }

    private function emptyPurchaseTotals(string $target): array
    {
        return [
            'purchases' => 0,
            'shown' => 0,
            'revenue_normalized' => 0.0,
            'normalized_currency' => $target,
            'distinct_visitors' => 0,
            'distinct_clients' => 0,
            'repeat_buyers' => 0,
            'normalization_meta' => [],
        ];
    }

    private function applyPlatformScope($query, int|array|null $platformIds): void
    {
        if (is_int($platformIds)) {
            $query->where('platform_id', $platformIds);

            return;
        }

        if (is_array($platformIds)) {
            if ($platformIds === []) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn('platform_id', $platformIds);
        }
    }
}
