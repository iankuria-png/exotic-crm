<?php

namespace App\Services;

use App\Models\ContactUnlockEvent;
use App\Models\Payment;
use App\Models\VisitorContactUnlock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ContactUnlockPulseService
{
    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService
    ) {}

    public function summary(?int $platformId = null, string $range = 'today', ?string $timezone = null, ?string $targetCurrency = null): array
    {
        [$from, $to] = $this->window($range, $timezone);
        $resolvedTargetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($targetCurrency);

        $eventBase = ContactUnlockEvent::query()
            ->when($platformId, fn ($query) => $query->where('platform_id', $platformId))
            ->whereBetween('occurred_at', [$from, $to]);

        $unlockBase = VisitorContactUnlock::query()
            ->when($platformId, fn ($query) => $query->where('platform_id', $platformId))
            ->whereBetween('created_at', [$from, $to]);

        $paymentBase = Payment::query()
            ->contactUnlockRevenue()
            ->when($platformId, fn ($query) => $query->where('platform_id', $platformId))
            ->whereBetween(DB::raw('COALESCE(completed_at, updated_at, created_at)'), [$from, $to]);

        $successfulPayments = (clone $paymentBase)->whereIn('status', Payment::SUCCESSFUL_STATUSES);
        $successfulNormalized = $this->reportingCurrencyService->normalizePaymentQuery(clone $successfulPayments, $resolvedTargetCurrency);
        $checkoutStarts = (int) (clone $unlockBase)->count();
        $ctaClicks = (int) (clone $eventBase)->where('event_type', ContactUnlockEvent::TYPE_CTA_CLICK)->count();
        $eligibleViews = (int) (clone $eventBase)->where('event_type', ContactUnlockEvent::TYPE_ELIGIBLE_VIEW)->count();
        $successfulCount = (int) (clone $successfulPayments)->count();
        $pendingPayments = (int) (clone $unlockBase)
            ->whereIn('status', [VisitorContactUnlock::STATUS_INITIATED, VisitorContactUnlock::STATUS_PENDING_PAYMENT])
            ->count();

        return [
            'range' => [
                'key' => $range,
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'kpis' => [
                'eligible_profile_views' => $eligibleViews,
                'unlock_cta_clicks' => $ctaClicks,
                'cta_rate_percent' => $this->percent($ctaClicks, $eligibleViews),
                'checkout_starts' => $checkoutStarts,
                'checkout_rate_percent' => $this->percent($checkoutStarts, $ctaClicks),
                'successful_payments' => $successfulCount,
                'pending_payments' => $pendingPayments,
                'payment_completion_percent' => $this->percent($successfulCount, $checkoutStarts),
                'unlock_conversion_percent' => $this->percent($successfulCount, $eligibleViews),
                'revenue' => $this->revenueRows(clone $successfulPayments),
                'revenue_normalized' => $successfulNormalized['normalized_total'],
                'revenue_normalized_display' => $successfulNormalized['normalized_display'],
                'revenue_normalization_meta' => $successfulNormalized['normalization_meta'],
                'normalized_currency' => $resolvedTargetCurrency,
                'average_order_value' => $this->averageRows(clone $successfulPayments),
                'average_order_value_normalized' => $successfulCount > 0 && $successfulNormalized['normalized_total'] !== null
                    ? round((float) $successfulNormalized['normalized_total'] / $successfulCount, 2)
                    : null,
                'single_profile_purchases' => $this->scopePurchases(clone $successfulPayments, VisitorContactUnlock::SCOPE_SINGLE_PROFILE),
                'full_access_purchases' => $this->scopePurchases(clone $successfulPayments, VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES),
                'repeat_buyer_percent' => $this->repeatBuyerPercent($platformId, $from, $to),
                'upgrade_rate_percent' => $this->upgradeRatePercent($platformId, $from, $to),
                'renewed_after_paid_demand' => $this->renewedAfterDemand($platformId, $from, $to),
            ],
            'top_cities' => $this->topCities($platformId, $from, $to),
            'top_profiles' => $this->topProfiles($platformId, $from, $to),
            'top_sources' => $this->topSources($platformId, $from, $to),
            'top_hours' => $this->topHours($platformId, $from, $to),
        ];
    }

    private function window(string $range, ?string $timezone): array
    {
        $tz = $timezone ?: config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($tz);
        $from = match ($range) {
            '7d' => $now->subDays(6)->startOfDay(),
            '30d' => $now->subDays(29)->startOfDay(),
            default => $now->startOfDay(),
        };

        return [$from->utc(), $now->utc()];
    }

    private function revenueRows($query): array
    {
        return $query
            ->select('currency', DB::raw('SUM(amount) as aggregate_amount'), DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => (string) ($row->currency ?: ''),
                'amount' => (float) $row->aggregate_amount,
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();
    }

    private function averageRows($query): array
    {
        return $query
            ->select('currency', DB::raw('AVG(amount) as aggregate_amount'), DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => [
                'currency' => (string) ($row->currency ?: ''),
                'amount' => round((float) $row->aggregate_amount, 2),
                'count' => (int) $row->aggregate_count,
            ])
            ->values()
            ->all();
    }

    private function scopePurchases($query, string $scope): int
    {
        return (int) $query
            ->whereHas('contactUnlock', fn ($unlockQuery) => $unlockQuery->where('scope', $scope))
            ->count();
    }

    private function repeatBuyerPercent(?int $platformId, $from, $to): float
    {
        $rows = VisitorContactUnlock::query()
            ->join('payments', 'payments.id', '=', 'visitor_contact_unlocks.payment_id')
            ->when($platformId, fn ($query) => $query->where('visitor_contact_unlocks.platform_id', $platformId))
            ->whereIn('payments.status', Payment::SUCCESSFUL_STATUSES)
            ->whereBetween(DB::raw('COALESCE(payments.completed_at, payments.updated_at, payments.created_at)'), [$from, $to])
            ->groupBy('visitor_contact_unlocks.visitor_phone_hash')
            ->select('visitor_contact_unlocks.visitor_phone_hash', DB::raw('COUNT(*) as aggregate_count'))
            ->get();

        if ($rows->isEmpty()) {
            return 0.0;
        }

        return round(($rows->where('aggregate_count', '>', 1)->count() / $rows->count()) * 100, 1);
    }

    private function upgradeRatePercent(?int $platformId, $from, $to): float
    {
        $buyers = VisitorContactUnlock::query()
            ->join('payments', 'payments.id', '=', 'visitor_contact_unlocks.payment_id')
            ->when($platformId, fn ($query) => $query->where('visitor_contact_unlocks.platform_id', $platformId))
            ->whereIn('payments.status', Payment::SUCCESSFUL_STATUSES)
            ->whereBetween(DB::raw('COALESCE(payments.completed_at, payments.updated_at, payments.created_at)'), [$from, $to])
            ->distinct()
            ->count('visitor_contact_unlocks.visitor_phone_hash');

        if ($buyers < 1) {
            return 0.0;
        }

        $upgraders = VisitorContactUnlock::query()
            ->join('payments', 'payments.id', '=', 'visitor_contact_unlocks.payment_id')
            ->when($platformId, fn ($query) => $query->where('visitor_contact_unlocks.platform_id', $platformId))
            ->where('visitor_contact_unlocks.scope', VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES)
            ->where('visitor_contact_unlocks.credit_amount', '>', 0)
            ->whereIn('payments.status', Payment::SUCCESSFUL_STATUSES)
            ->whereBetween(DB::raw('COALESCE(payments.completed_at, payments.updated_at, payments.created_at)'), [$from, $to])
            ->distinct()
            ->count('visitor_contact_unlocks.visitor_phone_hash');

        return round(($upgraders / $buyers) * 100, 1);
    }

    private function renewedAfterDemand(?int $platformId, $from, $to): int
    {
        $demandRows = VisitorContactUnlock::query()
            ->select('client_id', DB::raw('MIN(created_at) as first_demand_at'))
            ->whereNotNull('client_id')
            ->when($platformId, fn ($query) => $query->where('platform_id', $platformId))
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('payment', fn ($query) => $query->whereIn('status', Payment::SUCCESSFUL_STATUSES))
            ->groupBy('client_id')
            ->get();

        $count = 0;
        foreach ($demandRows as $row) {
            $firstDemand = CarbonImmutable::parse((string) $row->first_demand_at);
            $renewed = Payment::query()
                ->subscriptionRevenue()
                ->where('client_id', (int) $row->client_id)
                ->whereIn('status', Payment::SUCCESSFUL_STATUSES)
                ->whereBetween(DB::raw('COALESCE(completed_at, updated_at, created_at)'), [$firstDemand, $firstDemand->addDays(14)])
                ->exists();
            $count += $renewed ? 1 : 0;
        }

        return $count;
    }

    private function topCities(?int $platformId, $from, $to): array
    {
        return ContactUnlockEvent::query()
            ->join('clients', 'clients.id', '=', 'contact_unlock_events.client_id')
            ->when($platformId, fn ($query) => $query->where('contact_unlock_events.platform_id', $platformId))
            ->whereBetween('contact_unlock_events.occurred_at', [$from, $to])
            ->whereNotNull('clients.city')
            ->groupBy('clients.city')
            ->orderByDesc('aggregate_count')
            ->limit(6)
            ->get(['clients.city as label', DB::raw('COUNT(*) as aggregate_count')])
            ->map(fn ($row) => ['label' => (string) $row->label, 'count' => (int) $row->aggregate_count])
            ->values()
            ->all();
    }

    private function topProfiles(?int $platformId, $from, $to): array
    {
        return VisitorContactUnlock::query()
            ->join('payments', 'payments.id', '=', 'visitor_contact_unlocks.payment_id')
            ->leftJoin('clients', 'clients.id', '=', 'visitor_contact_unlocks.client_id')
            ->when($platformId, fn ($query) => $query->where('visitor_contact_unlocks.platform_id', $platformId))
            ->whereBetween('visitor_contact_unlocks.created_at', [$from, $to])
            ->whereIn('payments.status', Payment::SUCCESSFUL_STATUSES)
            ->groupBy('visitor_contact_unlocks.client_id', 'clients.name')
            ->orderByDesc('aggregate_count')
            ->limit(6)
            ->get(['clients.name as label', DB::raw('COUNT(*) as aggregate_count'), DB::raw('SUM(payments.amount) as aggregate_amount')])
            ->map(fn ($row) => ['label' => (string) ($row->label ?: 'All inactive contacts'), 'count' => (int) $row->aggregate_count, 'amount' => (float) $row->aggregate_amount])
            ->values()
            ->all();
    }

    private function topSources(?int $platformId, $from, $to): array
    {
        return ContactUnlockEvent::query()
            ->when($platformId, fn ($query) => $query->where('platform_id', $platformId))
            ->where('event_type', ContactUnlockEvent::TYPE_CHECKOUT_START)
            ->whereBetween('occurred_at', [$from, $to])
            ->groupBy('traffic_source')
            ->orderByDesc('aggregate_count')
            ->limit(6)
            ->get(['traffic_source as label', DB::raw('COUNT(*) as aggregate_count')])
            ->map(fn ($row) => ['label' => (string) ($row->label ?: 'unknown'), 'count' => (int) $row->aggregate_count])
            ->values()
            ->all();
    }

    private function topHours(?int $platformId, $from, $to): array
    {
        return ContactUnlockEvent::query()
            ->when($platformId, fn ($query) => $query->where('platform_id', $platformId))
            ->where('event_type', ContactUnlockEvent::TYPE_CHECKOUT_START)
            ->whereBetween('occurred_at', [$from, $to])
            ->whereNotNull('local_hour')
            ->groupBy('local_hour')
            ->orderByDesc('aggregate_count')
            ->limit(6)
            ->get(['local_hour as label', DB::raw('COUNT(*) as aggregate_count')])
            ->map(fn ($row) => ['label' => sprintf('%02d:00', (int) $row->label), 'count' => (int) $row->aggregate_count])
            ->values()
            ->all();
    }

    private function percent(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round(($numerator / $denominator) * 100, 1) : 0.0;
    }
}
