<?php

namespace App\Services\Forecast;

use App\Jobs\BuildForecastBaselineJob;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\ChurnAggregatorService;
use App\Services\ClientFunnelService;
use App\Services\PaymentRecoveryMetricService;
use App\Services\ReportingCurrencyService;
use App\Services\Revenue\CollectedRevenueQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ForecastBaselineService
{
    public function __construct(
        private readonly CollectedRevenueQuery $collectedRevenueQuery,
        private readonly ReportingCurrencyService $reportingCurrencyService,
        private readonly PaymentRecoveryMetricService $paymentRecoveryMetricService,
        private readonly ChurnAggregatorService $churnAggregatorService,
        private readonly SignupSourceConversionService $signupSourceConversionService
    ) {}

    public function response(ForecastContext $context, bool $cacheOnly = false): array
    {
        $key = $context->cacheKey();
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return [
                'state' => 'ready',
                'baseline' => $cached,
                'computed_at' => $cached['computed_at'] ?? now()->toIso8601String(),
            ];
        }

        if ($cacheOnly) {
            return ['state' => 'cold', 'baseline' => null];
        }

        $rowsEstimated = (clone $this->collectedRevenueQuery->builder($context->from, $context->to, $context->platformScope))->count();
        $rowBudget = (int) config('forecast.sync_row_budget');
        $days = $context->days;

        if ($rowsEstimated > $rowBudget) {
            return [
                'state' => 'refused',
                'reason' => 'window_too_wide',
                'rows_estimated' => $rowsEstimated,
                'row_budget' => $rowBudget,
                'suggested' => [
                    'from' => $context->to->copy()->subDays(29)->toDateString(),
                    'to' => $context->to->toDateString(),
                ],
            ];
        }

        if ($days > (int) config('forecast.queue_after_days')) {
            $tokenKey = "forecast:building-token:{$key}";
            $token = Cache::get($tokenKey);

            if (! is_string($token)) {
                $token = $this->jobToken($context);
                Cache::put($tokenKey, $token, now()->addMinutes(20));
                Cache::put($this->statusKey($token), [
                    'state' => 'building',
                    'progress' => ['phase' => 'queued', 'markets_done' => 0, 'markets_total' => $this->platforms($context)->count()],
                    'cache_key' => $key,
                ], now()->addMinutes(20));

                Cache::lock("forecast:build:{$key}", 60)->block(1, function () use ($context, $token) {
                    BuildForecastBaselineJob::dispatch($context->toArray(), $token)->onQueue('forecast');
                });
            }

            return [
                'state' => 'building',
                'job_token' => $token,
                'poll_after_ms' => 1500,
                'progress' => ['phase' => 'queued', 'markets_done' => 0, 'markets_total' => $this->platforms($context)->count()],
            ];
        }

        $baseline = $this->build($context);
        Cache::put($key, $baseline, now()->addSeconds((int) config('forecast.cache_ttl_seconds')));

        return [
            'state' => 'ready',
            'baseline' => $baseline,
            'computed_at' => $baseline['computed_at'],
        ];
    }

    public function status(string $jobToken): array
    {
        $status = Cache::get($this->statusKey($jobToken));

        if (! is_array($status)) {
            abort(404, 'Forecast baseline build token was not found.');
        }

        if (($status['state'] ?? null) === 'ready' && isset($status['cache_key'])) {
            $baseline = Cache::get($status['cache_key']);

            return [
                'state' => 'ready',
                'baseline' => $baseline,
            ];
        }

        return $status + ['poll_after_ms' => 2000];
    }

    public function build(ForecastContext $context): array
    {
        $revenue = $this->collectedRevenueQuery->total($context->from, $context->to, $context->platformScope, $context->currency);
        $monthlyRunRate = $context->days > 0 && $revenue['normalized_total'] !== null
            ? round(((float) $revenue['normalized_total'] / $context->days) * 30.4375, 2)
            : 0.0;

        $globalLevers = $this->leverBaselines($context, $context->platformScope);
        $markets = $this->platforms($context)
            ->map(function (Platform $platform) use ($context) {
                return [
                    'platform_id' => (int) $platform->id,
                    'market_label' => $platform->name,
                    'country' => $platform->country,
                    'levers' => $this->leverBaselines($context, (int) $platform->id),
                ];
            })
            ->values()
            ->all();

        return [
            'context' => $context->toArray(),
            'baseline_revenue' => $revenue,
            'projection' => [
                'monthly_run_rate' => $monthlyRunRate,
                'daily_run_rate' => $context->days > 0 && $revenue['normalized_total'] !== null ? round((float) $revenue['normalized_total'] / $context->days, 2) : 0.0,
                'horizon_days' => $context->horizonDays,
                'horizon_run_rate_total' => $context->days > 0 && $revenue['normalized_total'] !== null
                    ? round(((float) $revenue['normalized_total'] / $context->days) * $context->horizonDays, 2)
                    : 0.0,
            ],
            'levers' => $globalLevers,
            'per_market' => $markets,
            'normalization_meta' => $revenue['normalization_meta'],
            'computed_at' => now()->toIso8601String(),
            'config_digest' => $this->configDigest(),
        ];
    }

    public function configDigest(): string
    {
        return 'sha256:'.hash('sha256', json_encode(config('forecast'), JSON_THROW_ON_ERROR));
    }

    public function remember(ForecastContext $context, array $baseline): void
    {
        Cache::put($context->cacheKey(), $baseline, now()->addSeconds((int) config('forecast.cache_ttl_seconds')));
    }

    private function leverBaselines(ForecastContext $context, int|array|null $platformScope): array
    {
        $platformIds = is_int($platformScope) ? [$platformScope] : $platformScope;
        $recovery = $this->paymentRecoveryMetricService->compute($platformIds, $context->from, $context->to);
        $recoveredNormalized = $this->reportingCurrencyService->normalizeBreakdown(
            $recovery['recovered_amount_breakdown'] ?? [],
            $context->to,
            $context->currency,
            false
        );
        $lostNormalized = $this->reportingCurrencyService->normalizeBreakdown(
            $recovery['lost_amount_breakdown'] ?? [],
            $context->to,
            $context->currency,
            false
        );

        $movement = $this->churnAggregatorService->movement($context->from, $context->to, $platformIds ?? [], 'day');
        $churn = $this->churnAggregatorService->summary($context->from, $context->to, $platformIds ?? []);
        $signup = $this->signupSourceConversionService->summarize($context->from, $context->to, $platformScope, $context->currency);
        $renewal = $this->renewalStats($context, $platformScope);
        $activationTicket = $this->averagePaymentTicket($context, $platformScope, 'activation');
        $recoveryTicket = max(
            $recoveredNormalized['normalized_total'] !== null && (int) ($recovery['recovered_payments'] ?? 0) > 0
                ? (float) $recoveredNormalized['normalized_total'] / (int) $recovery['recovered_payments']
                : 0,
            $lostNormalized['normalized_total'] !== null && (int) ($recovery['lost_payments'] ?? 0) > 0
                ? (float) $lostNormalized['normalized_total'] / (int) $recovery['lost_payments']
                : 0
        );
        $churnCount = (int) data_get($churn, 'totals.churn', data_get($movement, 'totals.inactive_profiles', 0));
        $churnTicket = (float) data_get($churn, 'revenue_at_risk.average_ticket', 0);
        if ($churnTicket <= 0) {
            $churnTicket = $activationTicket;
        }

        return [
            'failed_recovery' => [
                'key' => 'failed_recovery',
                'label' => 'Failed payment recovery',
                'actual' => (float) ($recovery['payment_recovery_rate'] ?? 0),
                'suggested' => min(85.0, (float) ($recovery['payment_recovery_rate'] ?? 0) + 6.0),
                'eligible_units' => (int) ($recovery['failed_payments'] ?? 0),
                'unit_value' => round($recoveryTicket, 2),
                'unit' => 'percentage_points',
                'evidence' => [
                    'failed_payments' => (int) ($recovery['failed_payments'] ?? 0),
                    'recovered_payments' => (int) ($recovery['recovered_payments'] ?? 0),
                    'lost_payments' => (int) ($recovery['lost_payments'] ?? 0),
                    'payment_level_metric' => true,
                ],
            ],
            'new_activations' => [
                'key' => 'new_activations',
                'label' => 'New paid activations',
                'actual' => (float) data_get($movement, 'totals.new_paid_activations', 0),
                'suggested' => max((float) data_get($movement, 'totals.new_paid_activations', 0), ceil((float) data_get($movement, 'totals.new_paid_activations', 0) * 1.1)),
                'eligible_units' => (int) max(1, data_get($movement, 'totals.created_profiles', 0)),
                'unit_value' => $activationTicket,
                'unit' => 'count',
                'evidence' => [
                    'created_profiles' => (int) data_get($movement, 'totals.created_profiles', 0),
                    'new_paid_activations' => (int) data_get($movement, 'totals.new_paid_activations', 0),
                ],
            ],
            'signup_source_conversion' => [
                'key' => 'signup_source_conversion',
                'label' => 'Signup-source conversion',
                'actual' => (float) $signup['rate'],
                'suggested' => min(60.0, (float) $signup['rate'] + 5.0),
                'eligible_units' => (int) $signup['signups'],
                'unit_value' => (float) $signup['avg_ticket'],
                'unit' => 'percentage_points',
                'evidence' => [
                    'signups' => (int) $signup['signups'],
                    'converted' => (int) $signup['converted'],
                    'sources' => $signup['sources'],
                ],
            ],
            'renewal' => [
                'key' => 'renewal',
                'label' => 'Renewal rate',
                'actual' => (float) $renewal['rate'],
                'suggested' => min(85.0, (float) $renewal['rate'] + 5.0),
                'eligible_units' => (int) $renewal['eligible'],
                'unit_value' => (float) $renewal['avg_ticket'],
                'unit' => 'percentage_points',
                'evidence' => $renewal,
            ],
            'churn_winback' => [
                'key' => 'churn_winback',
                'label' => 'Churn win-back',
                'actual' => 0.0,
                'suggested' => min(40.0, max(5.0, $churnCount > 0 ? 10.0 : 0.0)),
                'eligible_units' => $churnCount,
                'unit_value' => round($churnTicket, 2),
                'unit' => 'percentage_points',
                'evidence' => [
                    'churned_profiles' => $churnCount,
                ],
            ],
            'new_market' => [
                'key' => 'new_market',
                'label' => 'New market',
                'actual' => 0.0,
                'suggested' => (float) config('forecast.new_market_default_monthly_target'),
                'eligible_units' => 1,
                'unit_value' => 1.0,
                'unit' => 'monthly_target',
                'evidence' => ['basis' => 'default editable launch ramp'],
            ],
            'agent_targets' => [
                'key' => 'agent_targets',
                'label' => 'Agent capacity',
                'actual' => 0.0,
                'suggested' => 0.0,
                'eligible_units' => 0,
                'unit_value' => 0.0,
                'unit' => 'capacity_check',
                'evidence' => ['description' => 'Cross-check only; not additive revenue.'],
            ],
        ];
    }

    private function renewalStats(ForecastContext $context, int|array|null $platformScope): array
    {
        $eligible = Deal::query()
            ->whereBetween('deals.expires_at', [$context->from, $context->to])
            ->whereIn('deals.status', ClientFunnelService::PAID_DEAL_STATUSES)
            ->where(fn (Builder $query) => $query->whereNull('deals.is_free_trial')->orWhere('deals.is_free_trial', false))
            ->where('deals.amount', '>', 0)
            ->when(is_int($platformScope), fn (Builder $query) => $query->where('deals.platform_id', $platformScope))
            ->when(is_array($platformScope), function (Builder $query) use ($platformScope) {
                empty($platformScope) ? $query->whereRaw('1 = 0') : $query->whereIn('deals.platform_id', $platformScope);
            })
            ->distinct()
            ->pluck('deals.client_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        $renewed = 0;
        $avgTicket = 0.0;
        if ($eligible->isNotEmpty()) {
            $renewalPayments = Payment::query()
                ->reportableSuccessful()
                ->excludingWalletTopups()
                ->leftJoin('deals', 'deals.id', '=', 'payments.deal_id')
                ->where(fn (Builder $query) => $query->whereIn('payments.client_id', $eligible)
                    ->orWhere(fn (Builder $fallback) => $fallback->whereNull('payments.client_id')->whereIn('deals.client_id', $eligible)))
                ->whereBetween(DB::raw('COALESCE(payments.completed_at, payments.created_at)'), [
                    $context->from,
                    $context->to->copy()->addDays((int) config('forecast.settle_days')),
                ])
                ->where(fn (Builder $query) => $query->where('payments.subscription_lifecycle', 'renewal')
                    ->orWhere('deals.subscription_lifecycle', 'renewal'));

            $renewed = (int) (clone $renewalPayments)
                ->distinct()
                ->count(DB::raw('COALESCE(payments.client_id, deals.client_id)'));
            $normalized = $this->reportingCurrencyService->normalizePaymentQuery(clone $renewalPayments, $context->currency, false);
            $avgTicket = $renewed > 0 && $normalized['normalized_total'] !== null
                ? round((float) $normalized['normalized_total'] / $renewed, 2)
                : 0.0;
        }

        return [
            'eligible' => (int) $eligible->count(),
            'renewed' => $renewed,
            'rate' => $eligible->count() > 0 ? round(($renewed / $eligible->count()) * 100, 1) : 0.0,
            'avg_ticket' => $avgTicket,
            'settle_days' => (int) config('forecast.settle_days'),
        ];
    }

    private function averagePaymentTicket(ForecastContext $context, int|array|null $platformScope, string $category): float
    {
        $query = $this->collectedRevenueQuery->builder($context->from, $context->to, $platformScope);
        $normalized = $this->reportingCurrencyService->normalizePaymentQuery(clone $query, $context->currency, false);
        $count = (int) (clone $query)->count();

        return $count > 0 && $normalized['normalized_total'] !== null
            ? round((float) $normalized['normalized_total'] / $count, 2)
            : 0.0;
    }

    private function platforms(ForecastContext $context)
    {
        return Platform::query()
            ->when($context->platformId, fn (Builder $query, int $id) => $query->whereKey($id))
            ->when(! $context->platformId && is_array($context->accessiblePlatformIds), function (Builder $query) use ($context) {
                empty($context->accessiblePlatformIds)
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('id', $context->accessiblePlatformIds);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'country']);
    }

    private function jobToken(ForecastContext $context): string
    {
        return 'fb_'.substr(hash('sha256', $context->cacheKey().'|'.now()->timestamp), 0, 24);
    }

    private function statusKey(string $token): string
    {
        return "forecast:baseline-status:{$token}";
    }
}
