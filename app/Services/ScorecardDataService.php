<?php

namespace App\Services;

use App\Models\ClientActiveSnapshot;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Platform;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ScorecardDataService
{
    public const AVAILABLE_SECTIONS = [
        'revenue',
        'client_snapshot',
        'daily_peak',
        'best_package',
        'conversion',
        'contact_mix',
    ];

    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService
    ) {
    }

    public function assemble(
        Carbon $from,
        Carbon $to,
        array $platformIds,
        ?string $targetCurrency,
        array $sections
    ): array {
        $requested = collect($sections)
            ->filter(fn ($section) => in_array($section, self::AVAILABLE_SECTIONS, true))
            ->values()
            ->all();

        $payload = [];

        foreach ($requested as $section) {
            $payload[$section] = match ($section) {
                'revenue' => $this->revenue($from, $to, $platformIds, $targetCurrency),
                'client_snapshot' => $this->clientSnapshot($from, $to, $platformIds),
                'daily_peak' => $this->dailyPeak($from, $to, $platformIds, $targetCurrency),
                'best_package' => $this->bestPackage($from, $to, $platformIds, $targetCurrency),
                'conversion' => $this->conversion($from, $to, $platformIds),
                'contact_mix' => $this->contactMix($from, $to, $platformIds),
                default => null,
            };
        }

        return $payload;
    }

    public function packageRevenue(Carbon $from, Carbon $to, array $platformIds, ?string $targetCurrency): array
    {
        $paymentCurrencyExpression = $this->paymentCurrencyExpression();
        $packageNameExpression = $this->paymentPackageExpression();
        $paymentDateExpression = $this->paymentDateExpression('payments.created_at');
        $platformCountryExpression = '(SELECT country FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1)';
        $platformNameExpression = '(SELECT name FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1)';

        return $this->successfulCollectedPaymentsQuery($from, $to, $platformIds)
            ->leftJoin('deals', 'deals.id', '=', 'payments.deal_id')
            ->leftJoin('products as payment_products', 'payment_products.id', '=', 'payments.product_id')
            ->leftJoin('products as deal_products', 'deal_products.id', '=', 'deals.product_id')
            ->selectRaw("{$packageNameExpression} as package_name")
            ->selectRaw("{$paymentDateExpression} as event_date")
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw("{$platformCountryExpression} as platform_country")
            ->selectRaw("{$platformNameExpression} as platform_name")
            ->selectRaw("{$paymentCurrencyExpression} as currency")
            ->selectRaw('SUM(payments.amount) as total')
            ->groupByRaw($packageNameExpression)
            ->groupByRaw($paymentDateExpression)
            ->groupBy('payments.platform_id')
            ->groupByRaw($platformCountryExpression)
            ->groupByRaw($platformNameExpression)
            ->groupByRaw($paymentCurrencyExpression)
            ->get()
            ->groupBy(fn ($row) => $row->package_name ?: 'Unknown package')
            ->map(function (Collection $rows, string $label) use ($targetCurrency) {
                $breakdown = [];
                $eventRows = [];

                foreach ($rows as $row) {
                    $breakdown[$row->currency] = ($breakdown[$row->currency] ?? 0.0) + (float) $row->total;
                    $eventRows[] = [
                        'event_date' => (string) $row->event_date,
                        'platform_id' => $row->platform_id,
                        'platform_country' => $row->platform_country,
                        'platform_name' => $row->platform_name,
                        'currency' => $row->currency,
                        'amount' => (float) $row->total,
                    ];
                }

                ksort($breakdown);
                $normalized = $this->reportingCurrencyService->normalizeEventRows($eventRows, $targetCurrency);

                return [
                    'label' => $label,
                    'value' => count($breakdown) === 1 ? array_values($breakdown)[0] : null,
                    'revenue_breakdown' => $breakdown,
                    'normalized_total' => $normalized['normalized_total'],
                    'normalized_currency' => $normalized['normalized_currency'],
                    'normalization_meta' => $normalized['normalization_meta'],
                    'sort_value' => $normalized['normalized_total'] ?? array_sum($breakdown),
                ];
            })
            ->sortByDesc('sort_value')
            ->take(10)
            ->map(fn (array $row) => [
                'label' => $row['label'],
                'value' => $row['value'],
                'revenue_breakdown' => $row['revenue_breakdown'],
                'normalized_total' => $row['normalized_total'],
                'normalized_currency' => $row['normalized_currency'],
                'normalization_meta' => $row['normalization_meta'],
            ])
            ->values()
            ->all();
    }

    private function revenue(Carbon $from, Carbon $to, array $platformIds, ?string $targetCurrency): array
    {
        $currencyExpression = "COALESCE(payments.currency, platforms.currency_code, 'KES')";
        $lifecycleExpression = "COALESCE(payments.subscription_lifecycle, deals.subscription_lifecycle, 'unknown')";
        $eventDateExpression = $this->paymentDateExpression('payments.created_at');

        $rows = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->leftJoin('deals', 'deals.id', '=', 'payments.deal_id')
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->whereBetween('payments.created_at', [$from, $to]);

        $this->applyPlatformFilter($rows, 'payments.platform_id', $platformIds);

        $rows = $rows
            ->selectRaw("{$lifecycleExpression} as lifecycle")
            ->selectRaw("{$currencyExpression} as currency")
            ->selectRaw("{$eventDateExpression} as event_date")
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw('SUM(payments.amount) as total')
            ->groupByRaw($lifecycleExpression)
            ->groupByRaw($currencyExpression)
            ->groupByRaw($eventDateExpression)
            ->groupBy('payments.platform_id')
            ->groupBy('platforms.country')
            ->groupBy('platforms.name')
            ->get();

        $buckets = collect(['new', 'renewal', 'unknown'])->mapWithKeys(function (string $bucket) use ($rows, $targetCurrency) {
            $bucketRows = $rows->where('lifecycle', $bucket)->values();

            return [$bucket => $this->summarizeGroupedRows($bucketRows, $targetCurrency, [
                'key' => $bucket,
                'label' => ucfirst($bucket),
            ])];
        })->all();

        return [
            'buckets' => $buckets,
            'total' => $this->summarizeGroupedRows($rows, $targetCurrency, [
                'key' => 'total',
                'label' => 'Total',
            ]),
        ];
    }

    private function clientSnapshot(Carbon $from, Carbon $to, array $platformIds): array
    {
        $snapshotQuery = ClientActiveSnapshot::query()
            ->when($platformIds !== [], fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds));

        $startCount = (int) (clone $snapshotQuery)
            ->whereDate('date', $from->toDateString())
            ->sum('count');
        $endCount = (int) (clone $snapshotQuery)
            ->whereDate('date', $to->toDateString())
            ->sum('count');

        return [
            'start_date' => $from->toDateString(),
            'start_count' => $startCount,
            'end_date' => $to->toDateString(),
            'end_count' => $endCount,
            'change' => $endCount - $startCount,
        ];
    }

    private function dailyPeak(Carbon $from, Carbon $to, array $platformIds, ?string $targetCurrency): array
    {
        $currencyExpression = $this->paymentCurrencyExpression();
        $dateExpression = $this->paymentDateExpression('payments.created_at');
        $platformCountryExpression = '(SELECT country FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1)';
        $platformNameExpression = '(SELECT name FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1)';

        $rows = $this->successfulCollectedPaymentsQuery($from, $to, $platformIds)
            ->selectRaw("{$dateExpression} as event_date")
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw("{$platformCountryExpression} as platform_country")
            ->selectRaw("{$platformNameExpression} as platform_name")
            ->selectRaw("{$currencyExpression} as currency")
            ->selectRaw('SUM(payments.amount) as total')
            ->groupByRaw($dateExpression)
            ->groupBy('payments.platform_id')
            ->groupByRaw($platformCountryExpression)
            ->groupByRaw($platformNameExpression)
            ->groupByRaw($currencyExpression)
            ->get()
            ->groupBy('event_date')
            ->map(function (Collection $groupedRows, string $date) use ($targetCurrency) {
                $summary = $this->summarizeGroupedRows($groupedRows, $targetCurrency, [
                    'date' => $date,
                ]);

                return [
                    'date' => $date,
                    'breakdown' => $summary['breakdown'],
                    'scalar_amount' => $summary['scalar_amount'],
                    'currency_count' => $summary['currency_count'],
                    'normalized_total' => $summary['normalized_total'],
                    'normalized_currency' => $summary['normalized_currency'],
                    'normalization_meta' => $summary['normalization_meta'],
                    'sort_value' => $summary['normalized_total'] ?? array_sum($summary['breakdown']),
                ];
            })
            ->sortBy('date')
            ->values();

        $sortedByValue = $rows->sortByDesc('sort_value')->values();

        return [
            'rows' => $rows->all(),
            'top_day' => $sortedByValue->first(),
            'low_day' => $rows->sortBy('sort_value')->values()->first(),
        ];
    }

    private function bestPackage(Carbon $from, Carbon $to, array $platformIds, ?string $targetCurrency): array
    {
        return [
            'rows' => $this->packageRevenue($from, $to, $platformIds, $targetCurrency),
        ];
    }

    private function conversion(Carbon $from, Carbon $to, array $platformIds): array
    {
        $funnelStageLabels = [
            'new' => 'New',
            'contacted' => 'Contacted',
            'qualified' => 'Qualified',
            'converted' => 'Converted',
            'lost' => 'Lost',
        ];

        $leadsQuery = Lead::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNull('archived_at');

        $this->applyPlatformFilter($leadsQuery, 'platform_id', $platformIds);

        $leadFunnel = [];
        foreach (array_keys($funnelStageLabels) as $stage) {
            $leadFunnel[$stage] = (int) (clone $leadsQuery)->where('status', $stage)->count();
        }

        $totalLeads = array_sum($leadFunnel);
        $conversionRate = $totalLeads > 0 ? round(($leadFunnel['converted'] / $totalLeads) * 100) : 0;
        $previousStageCount = null;
        $stages = [];

        foreach ($funnelStageLabels as $stageKey => $label) {
            $count = (int) ($leadFunnel[$stageKey] ?? 0);
            $conversionFromPrevious = null;
            $dropoffFromPrevious = null;

            if ($previousStageCount !== null && $previousStageCount > 0) {
                $conversionFromPrevious = round(($count / $previousStageCount) * 100, 1);
                $dropoffFromPrevious = max(0, round(100 - $conversionFromPrevious, 1));
            }

            $stages[] = [
                'key' => $stageKey,
                'label' => $label,
                'count' => $count,
                'share_of_total' => $totalLeads > 0 ? round(($count / $totalLeads) * 100, 1) : 0,
                'conversion_from_previous' => $conversionFromPrevious,
                'dropoff_from_previous' => $dropoffFromPrevious,
            ];

            $previousStageCount = $count;
        }

        return [
            'conversion_rate' => $conversionRate,
            'stages' => $stages,
            'totals' => [
                'total' => $totalLeads,
                'workable' => (int) (($leadFunnel['new'] ?? 0) + ($leadFunnel['contacted'] ?? 0) + ($leadFunnel['qualified'] ?? 0)),
                'converted' => (int) ($leadFunnel['converted'] ?? 0),
                'lost' => (int) ($leadFunnel['lost'] ?? 0),
            ],
        ];
    }

    private function contactMix(Carbon $from, Carbon $to, array $platformIds): array
    {
        $platforms = Platform::query()
            ->whereIn('id', $platformIds)
            ->orderBy('id')
            ->get(['id', 'name']);

        $rows = [];
        foreach ($platforms as $platform) {
            try {
                $payload = $this->analyticsClientFor((int) $platform->id)->getAnalyticsRankings([
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ]);

                $rows[] = [
                    'platform_id' => (int) $platform->id,
                    'platform_name' => (string) $platform->name,
                    'platform_contact_mix' => data_get($payload, 'platform_contact_mix', []),
                    'error' => null,
                ];
            } catch (\Throwable $exception) {
                $rows[] = [
                    'platform_id' => (int) $platform->id,
                    'platform_name' => (string) $platform->name,
                    'platform_contact_mix' => [],
                    'error' => $exception->getMessage(),
                ];
            }
        }

        return [
            'platforms' => $rows,
        ];
    }

    protected function analyticsClientFor(int $platformId): WpSyncService
    {
        return WpSyncService::forPlatform($platformId);
    }

    private function summarizeGroupedRows(Collection $rows, ?string $targetCurrency, array $base = []): array
    {
        $breakdown = [];
        $eventRows = [];

        foreach ($rows as $row) {
            $currency = (string) ($row->currency ?? 'KES');
            $amount = round((float) ($row->total ?? 0), 2);
            $breakdown[$currency] = ($breakdown[$currency] ?? 0.0) + $amount;
            $eventRows[] = [
                'event_date' => (string) ($row->event_date ?? now()->toDateString()),
                'platform_id' => $row->platform_id ?? null,
                'platform_country' => $row->platform_country ?? null,
                'platform_name' => $row->platform_name ?? null,
                'currency' => $currency,
                'amount' => $amount,
            ];
        }

        ksort($breakdown);
        $normalized = $this->reportingCurrencyService->normalizeEventRows($eventRows, $targetCurrency);

        return $base + [
            'breakdown' => $breakdown,
            'currency_count' => count($breakdown),
            'scalar_amount' => count($breakdown) === 1 ? array_values($breakdown)[0] : null,
            'normalized_total' => $normalized['normalized_total'],
            'normalized_currency' => $normalized['normalized_currency'],
            'normalization_meta' => $normalized['normalization_meta'],
        ];
    }

    private function successfulCollectedPaymentsQuery(Carbon $from, Carbon $to, array $platformIds): Builder
    {
        $query = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->whereBetween('payments.created_at', [$from, $to]);

        $this->applyPlatformFilter($query, 'payments.platform_id', $platformIds);

        return $query;
    }

    private function paymentCurrencyExpression(): string
    {
        return "COALESCE(payments.currency, (SELECT currency_code FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1), 'KES')";
    }

    private function paymentPackageExpression(): string
    {
        return "COALESCE(deal_products.name, payment_products.name, deals.plan_type, 'Unknown package')";
    }

    private function paymentDateExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "date({$column})"
            : "DATE({$column})";
    }

    private function applyPlatformFilter(Builder $builder, string $column, array $platformIds): Builder
    {
        if ($platformIds === []) {
            return $builder->whereRaw('1 = 0');
        }

        return $builder->whereIn($column, $platformIds);
    }
}
