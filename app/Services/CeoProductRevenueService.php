<?php

namespace App\Services;

use App\Models\AgentGoalOverride;
use App\Models\MarketRevenueTarget;
use App\Models\Platform;
use App\Services\Mcp\RevenueReportingQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** One request-local cash snapshot for the card, product contribution and country focus. */
final class CeoProductRevenueService
{
    private const PRODUCTS = [
        'subscription' => ['Subscription revenue', ['subscription', 'renewal']],
        'contact_unlock' => ['Contact unlocks', ['contact_unlock']],
        'premium_content_sale' => ['Content sales', ['premium_content_sale']],
        'send_love' => ['Gifts', ['send_love']],
        'passes' => ['Passes', ['tour_pass', 'monetize_pass']],
    ];

    public function __construct(
        private readonly RevenueReportingQuery $query,
        private readonly ReportingCurrencyService $fx,
        private readonly MarketAuthorizationService $markets,
    ) {}

    public function report(Request $request): array
    {
        $asOf = CarbonImmutable::now('Africa/Nairobi');
        $request->validate([
            'platform_id' => 'nullable|integer|min:1|exists:platforms,id',
            'horizon' => 'nullable|in:today,day,7d,30d,90d,ytd,custom',
            'date' => 'nullable|date_format:Y-m-d|before_or_equal:'.$asOf->toDateString(),
            'from' => 'required_if:horizon,custom|date_format:Y-m-d',
            'to' => 'required_if:horizon,custom|date_format:Y-m-d|after_or_equal:from',
            'reporting_currency' => 'nullable|string|size:3|regex:/^[A-Za-z]{3}$/',
        ]);
        $target = $this->fx->resolveTargetCurrency($request->query('reporting_currency', 'USD'));
        $scope = $this->markets->resolveAccessiblePlatformIds($request->user());
        $platformId = $request->integer('platform_id') ?: null;
        $this->markets->ensureUserCanAccessPlatform($request->user(), $platformId);
        $platforms = Platform::query()->when(is_array($scope), fn ($q) => $q->whereIn('id', $scope))
            ->when($platformId, fn ($q) => $q->whereKey($platformId))->orderBy('id')->get(['id', 'name', 'country', 'currency_code']);
        [$from, $requestedEnd] = $this->bounds($request, $asOf);
        abort_if($from->gt($asOf), 422, 'Choose a reporting period that has started.');
        $to = $requestedEnd->min($asOf);
        $days = (int) $from->diffInDays($requestedEnd);
        $priorFrom = $from->subDays($days);
        // Compare equal elapsed intervals, including the partial current day.
        $priorTo = $priorFrom->addSeconds($from->diffInSeconds($to));
        $intervals = [
            'selected' => [$from, $to], 'prior' => [$priorFrom, $priorTo],
            'today' => [$asOf->startOfDay(), $asOf],
            'week' => [$asOf->startOfWeek(CarbonImmutable::MONDAY), $asOf],
            'month' => [$asOf->startOfMonth(), $asOf],
        ];
        $earliest = collect($intervals)->map(fn ($bounds) => $bounds[0])->sort()->first();
        $rows = $this->query->rows()->whereIn('reported.platform_id', $platforms->pluck('id'))
            ->where('reported.occurred_at', '>=', $earliest->utc()->format('Y-m-d H:i:s.u'))
            ->where('reported.occurred_at', '<', $asOf->utc()->format('Y-m-d H:i:s.u'))
            ->orderBy('reported.id')->limit(100001)->get();
        abort_if($rows->count() > 100000, 422, 'Narrow the reporting period; it exceeds 100,000 records.');
        // Convert each receipt once to integer cents, matching exact MCP cash policy.
        // Every subtotal then sums those same cents; FX aggregation cannot drift by a cent.
        $rows = $rows->map(function ($row) use ($target) {
            $row->instant = CarbonImmutable::parse($row->occurred_at, 'UTC');
            $money = $this->fx->normalizeEventRows([(object) [
                'platform_id' => $row->platform_id, 'platform_country' => $row->country,
                'currency' => $row->currency, 'amount' => $row->amount,
                'event_date' => $row->instant->toDateString(),
            ]], $target, false);
            $row->native_minor = (int) round($row->amount * 100);
            $row->normalized_minor = $money['normalized_total'] === null ? null : (int) round($money['normalized_total'] * 100);
            $row->fx_stale = (bool) data_get($money, 'normalization_meta.stale');

            return $row;
        });
        $within = fn (Collection $items, array $bounds) => $items->filter(fn ($r) => $r->instant->gte($bounds[0]) && $r->instant->lt($bounds[1]));
        $included = $rows->where('disposition', 'included');
        $cash = $included->where('funding', 'new_cash');
        $periods = collect($intervals)->map(fn ($bounds) => $within($cash, $bounds));
        $selected = $periods['selected'];
        $prior = $periods['prior'];
        $total = $this->money($selected, $target);
        $priorTotal = $this->money($prior, $target);
        $comparison = function (Collection $current, Collection $previous) use ($target, $total): array {
            $money = $this->money($current, $target);
            $before = $this->money($previous, $target);

            return $money + ['prior' => $before,
                'share_percent' => $money['normalized_total'] !== null && $total['normalized_total'] > 0 ? round(100 * $money['normalized_total'] / $total['normalized_total'], 2) : null,
                'change_percent' => $this->change($money, $before)];
        };
        $products = collect(self::PRODUCTS)->map(function ($definition, $key) use ($selected, $prior, $comparison, $within, $included, $intervals, $target) {
            [$label, $categories] = $definition;

            return ['key' => $key, 'label' => $label,
                ...$comparison($selected->whereIn('category', $categories), $prior->whereIn('category', $categories)),
                'wallet_funded' => $this->money($within($included->where('funding', 'wallet_redemption')->whereIn('category', $categories), $intervals['selected']), $target),
                'categories' => collect($categories)->map(fn ($category) => ['category' => $category, ...$this->money($selected->where('category', $category), $target)])->values()->all()];
        })->values()->all();
        $explicitTargets = MarketRevenueTarget::whereIn('platform_id', $platforms->pluck('id'))->where('target_currency', $target)->get()->groupBy('platform_id');
        $fallbackTargets = AgentGoalOverride::whereIn('platform_id', $platforms->pluck('id'))->where('metric', 'revenue')
            ->where(fn ($q) => $q->whereNull('target_currency')->orWhere('target_currency', $target))->get()->groupBy('platform_id');
        $siteRows = $platforms->map(function ($platform) use ($periods, $comparison, $target, $asOf, $explicitTargets, $fallbackTargets) {
            $id = $platform->id;
            $site = ['platform_id' => (int) $id, 'name' => $platform->name, 'country' => $platform->country, 'currency' => $platform->currency_code,
                ...$comparison($periods['selected']->where('platform_id', $id), $periods['prior']->where('platform_id', $id)),
                'today' => $this->money($periods['today']->where('platform_id', $id), $target),
                'week' => $this->money($periods['week']->where('platform_id', $id), $target)];
            $site['targets'] = $this->targets($id, $periods, $target, $asOf, $explicitTargets->get($id, collect()), $fallbackTargets->get($id, collect()));

            return $site;
        });
        $countries = $platforms->groupBy(fn ($p) => $this->countryKey($p->country))->map(function ($sites, $key) use ($periods, $comparison, $target, $siteRows) {
            $ids = $sites->pluck('id');

            return ['key' => $key, 'country' => $key === 'rwanda' ? 'Rwanda' : ($sites->first()->country ?: 'Unassigned'),
                ...$comparison($periods['selected']->whereIn('platform_id', $ids), $periods['prior']->whereIn('platform_id', $ids)),
                'today' => $this->money($periods['today']->whereIn('platform_id', $ids), $target),
                'week' => $this->money($periods['week']->whereIn('platform_id', $ids), $target),
                'platforms' => $siteRows->whereIn('platform_id', $ids)->values()->all()];
        })->sortByDesc(fn ($r) => $r['normalized_total'] ?? -1)->values()->all();
        $known = collect(self::PRODUCTS)->flatMap(fn ($p) => $p[1])->all();

        return [
            'as_of' => $asOf->toIso8601String(), 'timezone' => 'Africa/Nairobi', 'basis' => 'provisional_external_cash',
            'window' => $this->window($from, $to) + ['requested_to' => $requestedEnd->toIso8601String(), 'partial' => $to->lt($requestedEnd), 'days' => $days],
            'prior_window' => $this->window($priorFrom, $priorTo),
            'selected' => $total + ['change_percent' => $this->change($total, $priorTotal)], 'prior' => $priorTotal,
            'today' => $this->money($periods['today'], $target), 'week' => $this->money($periods['week'], $target), 'month' => $this->money($periods['month'], $target),
            'period_windows' => collect($intervals)->map(fn ($b) => $this->window(...$b))->all(),
            'products' => $products, 'markets' => $countries,
            'coverage' => [
                'ambiguous_receipts' => $this->money($within($rows->where('disposition', 'ambiguous_receipt'), $intervals['selected']), $target),
                'unknown_funding' => $this->money($within($included->where('funding', 'unknown'), $intervals['selected']), $target),
                'unmapped_products' => $this->money($selected->whereNotIn('category', $known), $target),
                'missing_account_scope_count' => $selected->whereNull('account_key')->count(),
                'missing_reference_count' => $selected->filter(fn ($r) => ! $r->receipt_key)->count(),
            ],
        ];
    }

    private function bounds(Request $request, CarbonImmutable $now): array
    {
        $horizon = $request->query('horizon', '30d');
        $from = match ($horizon) {
            'custom' => CarbonImmutable::parse($request->query('from'), $now->timezone)->startOfDay(),
            'today', 'day' => CarbonImmutable::parse($request->query('date', $now->toDateString()), $now->timezone)->startOfDay(),
            '7d' => $now->subDays(6)->startOfDay(), '90d' => $now->subDays(89)->startOfDay(),
            'ytd' => $now->startOfYear(), default => $now->subDays(29)->startOfDay(),
        };
        $to = match ($horizon) {
            'custom' => CarbonImmutable::parse($request->query('to'), $now->timezone)->startOfDay()->addDay(),
            'today', 'day' => $from->addDay(), default => $now->startOfDay()->addDay(),
        };

        return [$from, $to];
    }

    private function money(Collection $rows, string $target): array
    {
        $native = $rows->groupBy('currency')->map(fn ($r) => $r->sum('native_minor') / 100)->sortKeys()->all();
        $missing = $rows->whereNull('normalized_minor');

        return ['native' => $native, 'normalized_total' => $missing->isNotEmpty() ? null : $rows->sum('normalized_minor') / 100,
            'normalized_currency' => $target, 'payments_count' => $rows->count(),
            'fx_coverage' => ['complete' => $missing->isEmpty(), 'missing_payments_count' => $missing->count(),
                'missing_currencies' => $missing->pluck('currency')->unique()->values()->all(), 'stale_payments_count' => $rows->where('fx_stale', true)->count()]];
    }

    private function change(array $current, array $prior): ?float
    {
        return $current['normalized_total'] !== null && $prior['normalized_total'] > 0
            ? round(100 * ($current['normalized_total'] - $prior['normalized_total']) / $prior['normalized_total'], 1) : null;
    }

    private function window(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'boundaries' => '[from,to)'];
    }

    private function countryKey(?string $country): string
    {
        $key = mb_strtolower(trim($country ?? ''));

        return match ($key) {
            'rw', 'rwanda' => 'rwanda', default => $key ?: 'unassigned'
        };
    }

    private function targets(int $id, Collection $periods, string $target, CarbonImmutable $now, Collection $explicit, Collection $fallbacks): array
    {
        // Goals retain their configured cadence. A 30-day selection is never a weekly actual.
        return collect(['weekly' => 'week', 'monthly' => 'month'])->map(function ($period, $cadence) use ($explicit, $fallbacks, $periods, $id, $target, $now) {
            $goals = $explicit->where('period', $cadence);
            $source = 'market_target';
            if ($goals->isEmpty()) {
                $goals = $fallbacks->where('period', $cadence);
                $source = 'agent_allocations';
            }
            if ($goals->isEmpty() || $goals->sum('target') <= 0) {
                return null;
            }
            $actual = $this->money($periods[$period]->where('platform_id', $id), $target);
            $amount = (float) $goals->sum('target');

            return ['period' => $cadence, 'source' => $source, 'target' => $amount, 'target_currency' => $target,
                'actual' => $actual, 'percentage' => $actual['normalized_total'] === null ? null : round(100 * $actual['normalized_total'] / $amount, 1),
                'as_of' => $now->toIso8601String()];
        })->filter()->values()->all();
    }
}
