<?php

namespace App\Services\Mcp;

use App\Models\Platform;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Services\ReportingCurrencyService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class RevenueReportingService
{
    public function __construct(
        private readonly RevenueReportingQuery $query,
        private readonly ReportingCurrencyService $fx,
        private readonly MarketAuthorizationService $markets,
    ) {}

    public function report(array $arguments, User $user): array
    {
        $window = RevenueReportingWindow::fromArguments($arguments);
        $platforms = Platform::query()->when(is_array($scope = $this->markets->resolveAccessiblePlatformIds($user)), fn ($q) => $q->whereIn('id', $scope));
        if (isset($arguments['platform_id'])) {
            $id = filter_var($arguments['platform_id'], FILTER_VALIDATE_INT);
            if (! $id || (is_array($scope) && ! in_array($id, $scope, true)) || ! Platform::whereKey($id)->exists()) {
                throw McpProtocolException::rpc(-32602, 'Requested platform is unavailable to this account.', 'invalid_params', 422);
            }
            $platforms->whereKey($id);
        }
        if (isset($arguments['country'])) {
            $country = trim((string) $arguments['country']);
            if ($country === '' || mb_strlen($country) > 80) {
                throw McpProtocolException::rpc(-32602, 'Invalid country.', 'invalid_params', 422);
            }
            $platforms->whereRaw('LOWER(country) = ?', [mb_strtolower($country)]);
        }
        $platforms = $platforms->orderBy('id')->get(['id', 'name', 'country']);
        if (isset($arguments['country']) && $platforms->isEmpty()) {
            throw McpProtocolException::rpc(-32602, 'Country is unknown or outside this account scope.', 'invalid_params', 422);
        }
        $rows = $this->query->rows()->whereIn('reported.platform_id', $platforms->pluck('id'))
            ->where('reported.occurred_at', '>=', rtrim(rtrim($window->from->format('Y-m-d H:i:s.u'), '0'), '.'))
            ->where('reported.occurred_at', '<', rtrim(rtrim($window->to->format('Y-m-d H:i:s.u'), '0'), '.'))
            ->orderBy('reported.id')->limit(50001)->get();
        if ($rows->count() > 50000) {
            throw McpProtocolException::rpc(-32602, 'Reporting window exceeds 50,000 payment records; narrow the interval.', 'report_limit', 422);
        }
        $rows = $rows->map(function ($row) {
            $date = CarbonImmutable::parse($row->occurred_at, 'UTC')->toDateString();
            $normalized = $this->fx->normalizeEventRows([(object) [
                'platform_id' => $row->platform_id, 'platform_country' => $row->country,
                'currency' => $row->currency, 'amount' => $row->amount, 'event_date' => $date,
            ]], 'USD', false);
            $row->normalized_minor = $normalized['normalized_total'] === null ? null : (int) round($normalized['normalized_total'] * 100);
            $row->native_minor = (int) round((float) $row->amount * 100);
            $row->fx_stale = (bool) data_get($normalized, 'normalization_meta.stale');

            return $row;
        });
        $included = $rows->where('disposition', 'included');
        $excluded = $rows->where('disposition', 'ambiguous_receipt');
        $categories = $included->groupBy('category')->map(fn ($group, $category) => ['category' => $category, ...$this->totals($group)])->values()->all();
        $siteRows = $platforms->map(function ($platform) use ($included) {
            $group = $included->where('platform_id', $platform->id);

            return ['platform_id' => (int) $platform->id, 'platform_label' => $platform->name, 'country' => $platform->country,
                ...$this->totals($group),
                'categories' => $group->groupBy('category')->map(fn ($items, $category) => ['category' => $category, ...$this->totals($items)])->values()->all()];
        });
        $countries = $siteRows->groupBy(fn ($row) => mb_strtolower(trim((string) $row['country'])))
            ->map(function ($sites) use ($included) {
                return ['country' => $sites->first()['country'], ...$this->totals($included->whereIn('platform_id', $sites->pluck('platform_id'))), 'platforms' => $sites->values()->all()];
            })->values()->all();
        $caveats = [
            'Successful CRM records only; provider settlement and historical completeness are not independently verified.',
            'New-cash totals exclude wallet redemptions and unknown funding. Earned-service value includes both, but excludes wallet top-ups.',
            'Subscription and renewal classification uses payment.subscription_lifecycle; absent lifecycle remains subscription, not an inferred renewal.',
            'Occurrence uses completed_at, then confirmed_at, then created_at; fallback counts are reported.',
            'Ambiguous repeated receipts are excluded across retained history before window filtering; valid split-bundle allocations remain additive.',
            'USD uses cached historical FX policy only; no live rate requests. Missing rates return null USD and retain native amounts.',
            'Exact collections always report USD plus original currencies; reporting_currency applies only to the unchanged default dashboard contract.',
            'Missing receiving-account provenance uses a platform/provider fallback; cross-platform receipt relationships may remain undiscovered.',
        ];
        $coverage = [
            'generated_at' => now()->toIso8601String(), 'assurance' => 'crm_recorded_only',
            'platform_count' => $platforms->count(), 'candidate_payments_count' => $rows->count(),
            'included_payments_count' => $included->count(), 'ambiguous_receipt_records' => $this->money($excluded),
            'clock_sources' => $included->countBy('clock_source')->all(),
            'missing_reference_count' => $included->filter(fn ($r) => ! $r->receipt_key)->count(),
            'missing_account_scope_count' => $included->whereNull('account_key')->count(),
            'unknown_funding' => $this->money($included->where('funding', 'unknown')),
            'fx_missing_payment_count' => $included->whereNull('normalized_minor')->count(),
            'fx_stale_payment_count' => $included->where('fx_stale', true)->count(),
            'caveats' => $caveats,
        ];
        $partial = $excluded->isNotEmpty() || $coverage['fx_missing_payment_count'] > 0 || $coverage['missing_reference_count'] > 0
            || $coverage['unknown_funding']['payments_count'] > 0 || $coverage['missing_account_scope_count'] > 0;

        return [
            'window' => $window->serialize() + ['reporting_currency' => 'USD'], ...$this->totals($included),
            'categories' => $categories, 'countries' => $countries, 'platforms' => $siteRows->all(), 'coverage' => $coverage,
            '_report_meta' => ['result_state' => $partial ? 'partial' : ($included->isEmpty() ? 'no_data' : 'complete'),
                'coverage' => [$coverage], 'caveats' => $caveats, 'row_count' => $included->count(), 'filters' => $window->serialize() + ['country' => $arguments['country'] ?? null, 'platform_id' => $arguments['platform_id'] ?? null, 'reporting_currency' => 'USD', 'requested_reporting_currency' => $arguments['reporting_currency'] ?? null]],
            // Private transient rows are removed by summary/trend/market presenters.
            '_rows' => $included, '_window' => $window,
        ];
    }

    public function summary(array $arguments, User $user): array
    {
        $report = $this->report($arguments, $user);
        $rows = $report['_rows'];
        $subscription = $this->money($rows->whereIn('category', ['subscription', 'renewal']));
        $visitor = $this->money($rows->where('category', 'contact_unlock'));
        unset($report['_rows'], $report['_window']);
        $meta = $report['_report_meta'];
        unset($report['_report_meta']);

        return ['window' => $report['window'],
            'metrics' => ['collected_revenue' => ['label' => 'Subscription earned-service value (includes wallet redemptions)', 'value' => $subscription,
                'prior_value' => null, 'delta_percent' => null]],
            'customer_mix' => ['availability' => 'unavailable', 'reason' => 'Exact-window collections do not calculate customer-mix or active-subscriber snapshots.'],
            'visitor_revenue' => $visitor, 'insights' => [], 'collections' => $report, '_report_meta' => $meta];
    }

    public function trend(array $arguments, User $user): array
    {
        $report = $this->report($arguments, $user);
        $window = $report['_window'];
        $bucket = (string) ($arguments['bucket'] ?? 'day');
        if (! in_array($bucket, ['hour', 'day', 'week', 'month'], true)) {
            throw McpProtocolException::rpc(-32602, 'Unsupported revenue bucket.', 'invalid_params', 422);
        }
        $start = fn ($date) => match ($bucket) {
            'hour' => $date->startOfHour(), 'week' => $date->startOfWeek(), 'month' => $date->startOfMonth(), default => $date->startOfDay(),
        };
        $label = fn ($date) => $date->toIso8601String();
        $grouped = $report['_rows']->groupBy(fn ($row) => $label($start(CarbonImmutable::parse($row->occurred_at, 'UTC')->setTimezone($window->timezone))));
        $points = [];
        $cursor = $start($window->from->setTimezone($window->timezone));
        while ($cursor->utc()->lessThan($window->to)) {
            if (count($points) >= 9000) {
                throw McpProtocolException::rpc(-32602, 'Too many trend buckets; request a coarser bucket.', 'report_limit', 422);
            }
            $key = $label($cursor);
            $totals = $this->totals($grouped->get($key, collect()));
            $points[] = ['label' => $key, 'value' => $totals['new_cash']['normalized_total'],
                'normalized_currency' => 'USD', 'source_breakdown' => $totals['new_cash']['source_breakdown'], 'payments_count' => $totals['new_cash']['payments_count'], ...$totals];
            $cursor = match ($bucket) {
                'hour' => $cursor->addHour(), 'week' => $cursor->addWeek(), 'month' => $cursor->addMonth(), default => $cursor->addDay(),
            };
        }

        return ['window' => $report['window'], 'bucket' => $bucket, 'points' => $points,
            'coverage' => $report['coverage'], '_report_meta' => $report['_report_meta']];
    }

    public function marketBreakdown(array $arguments, User $user): array
    {
        $report = $this->report($arguments, $user);

        return ['window' => $report['window'], 'total' => $report['new_cash']['normalized_total'],
            'channels' => [], 'markets' => array_map(fn ($row) => $row + [
                'value' => $row['new_cash']['normalized_total'], 'normalized_total' => $row['new_cash']['normalized_total'], 'normalized_currency' => 'USD',
                'source_breakdown' => $row['new_cash']['source_breakdown'], 'payments_count' => $row['new_cash']['payments_count'],
                'share_percent' => $report['new_cash']['normalized_total'] !== null && $report['new_cash']['normalized_total'] > 0 && $row['new_cash']['normalized_total'] !== null
                    ? round($row['new_cash']['normalized_total'] / $report['new_cash']['normalized_total'] * 100, 1) : null,
            ], $report['platforms']), 'countries' => $report['countries'],
            'coverage' => $report['coverage'], '_report_meta' => $report['_report_meta']];
    }

    private function totals(Collection $rows): array
    {
        return ['new_cash' => $this->money($rows->where('funding', 'new_cash')),
            'earned_services' => $this->money($rows), 'wallet_redemptions' => $this->money($rows->where('funding', 'wallet_redemption')),
            'unknown_funding' => $this->money($rows->where('funding', 'unknown'))];
    }

    private function money(Collection $rows): array
    {
        $native = $rows->groupBy('currency')->map(fn ($group) => $group->sum('native_minor') / 100)->sortKeys()->all();
        $partial = $rows->contains(fn ($row) => $row->normalized_minor === null);

        return ['source_breakdown' => $native, 'normalized_total' => $partial ? null : $rows->sum('normalized_minor') / 100,
            'normalized_currency' => 'USD', 'payments_count' => $rows->count(),
            'normalization_meta' => ['partial' => $partial, 'cached_only' => true, 'stale' => $rows->contains(fn ($row) => $row->fx_stale)]];
    }
}
