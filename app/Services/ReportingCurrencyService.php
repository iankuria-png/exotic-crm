<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use App\Models\ReportingFxRate;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ReportingCurrencyService
{
    public const SETTINGS_KEY = 'reporting_currency';
    public const MODE_NATIVE = 'native';
    public const MODE_FLAT = 'flat';
    public const DEFAULT_TARGET_CURRENCY = 'USD';

    public function settings(): array
    {
        $stored = IntegrationSetting::query()
            ->where('key', self::SETTINGS_KEY)
            ->value('value');

        $stored = is_array($stored) ? $stored : [];
        $targetCurrency = $this->normalizeCurrency(
            $stored['target_currency'] ?? config('services.reporting_fx.default_currency', self::DEFAULT_TARGET_CURRENCY),
            self::DEFAULT_TARGET_CURRENCY
        );
        $provider = trim((string) ($stored['provider'] ?? config('services.reporting_fx.provider', 'currencyapi')));

        return [
            'enabled' => (bool) ($stored['enabled'] ?? config('services.reporting_fx.enabled', false)),
            'target_currency' => $targetCurrency,
            'provider' => $provider !== '' ? $provider : 'currencyapi',
            'allow_user_override' => (bool) ($stored['allow_user_override'] ?? config('services.reporting_fx.allow_user_override', true)),
            'stale_days' => max(0, (int) ($stored['stale_days'] ?? config('services.reporting_fx.stale_days', 7))),
            'rate_policy' => (string) ($stored['rate_policy'] ?? 'historical_locked'),
            'fallback_behavior' => (string) ($stored['fallback_behavior'] ?? 'partial_with_native'),
            'last_checked_at' => $stored['last_checked_at'] ?? null,
            'health' => $stored['health'] ?? [
                'status' => config('services.reporting_fx.api_key') ? 'configured' : 'missing_api_key',
                'message' => config('services.reporting_fx.api_key')
                    ? 'FX provider credentials are configured.'
                    : 'FX provider API key is not configured; cached rates can still be used.',
            ],
        ];
    }

    public function updateSettings(array $input, ?int $userId = null): array
    {
        $current = $this->settings();
        $next = array_merge($current, [
            'enabled' => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $current['enabled'],
            'target_currency' => $this->normalizeCurrency($input['target_currency'] ?? $current['target_currency'], self::DEFAULT_TARGET_CURRENCY),
            'provider' => trim((string) ($input['provider'] ?? $current['provider'])) ?: 'currencyapi',
            'allow_user_override' => array_key_exists('allow_user_override', $input) ? (bool) $input['allow_user_override'] : $current['allow_user_override'],
            'stale_days' => max(0, (int) ($input['stale_days'] ?? $current['stale_days'])),
            'rate_policy' => (string) ($input['rate_policy'] ?? $current['rate_policy']),
            'fallback_behavior' => (string) ($input['fallback_behavior'] ?? $current['fallback_behavior']),
        ]);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            [
                'value' => $next,
                'updated_by' => $userId,
            ]
        );

        return $next;
    }

    public function resolveMode(?string $requestedMode, bool $preferFlat = false): string
    {
        $normalized = strtolower(trim((string) $requestedMode));

        if (in_array($normalized, [self::MODE_NATIVE, self::MODE_FLAT], true)) {
            return $normalized;
        }

        return $preferFlat ? self::MODE_FLAT : self::MODE_NATIVE;
    }

    public function resolveTargetCurrency(?string $requestedCurrency = null): string
    {
        $settings = $this->settings();

        if (($settings['allow_user_override'] ?? true) && trim((string) $requestedCurrency) !== '') {
            return $this->normalizeCurrency($requestedCurrency, $settings['target_currency']);
        }

        return $this->normalizeCurrency($settings['target_currency'] ?? self::DEFAULT_TARGET_CURRENCY, self::DEFAULT_TARGET_CURRENCY);
    }

    /**
     * @param  array<string, float|int|string|null>  $breakdown
     */
    public function normalizeBreakdown(array $breakdown, ?CarbonInterface $eventDate = null, ?string $targetCurrency = null): array
    {
        $settings = $this->settings();
        $target = $this->normalizeCurrency($targetCurrency ?? $settings['target_currency'] ?? self::DEFAULT_TARGET_CURRENCY, self::DEFAULT_TARGET_CURRENCY);
        $date = $eventDate ? Carbon::instance($eventDate)->toDateString() : now()->toDateString();
        $sourceBreakdown = $this->normalizeBreakdownInput($breakdown);
        $rows = [];
        $normalizedTotal = 0.0;
        $missing = [];
        $stale = false;
        $asOfDates = [];

        foreach ($sourceBreakdown as $currency => $amount) {
            if ($currency === $target) {
                $rate = 1.0;
                $rateDate = $date;
                $provider = 'identity';
                $rateStale = false;
            } else {
                $snapshot = $this->resolveRate($currency, $target, $date, $settings);

                if (!$snapshot) {
                    $missing[] = $currency;
                    $rows[] = [
                        'source_currency' => $currency,
                        'source_amount' => $amount,
                        'rate' => null,
                        'rate_date' => null,
                        'normalized_amount' => null,
                        'stale' => false,
                    ];
                    continue;
                }

                $rate = (float) $snapshot->rate;
                $rateDate = $snapshot->rate_date?->toDateString() ?: $date;
                $provider = (string) $snapshot->provider;
                $rateStale = $rateDate !== $date;
            }

            $converted = round($amount * $rate, 2);
            $normalizedTotal += $converted;
            $stale = $stale || $rateStale;
            $asOfDates[] = $rateDate;
            $rows[] = [
                'source_currency' => $currency,
                'source_amount' => $amount,
                'rate' => $rate,
                'rate_date' => $rateDate,
                'provider' => $provider,
                'normalized_amount' => $converted,
                'stale' => $rateStale,
            ];
        }

        sort($asOfDates);
        $partial = count($missing) > 0;
        $normalizedValue = count($sourceBreakdown) === 0
            ? 0.0
            : ($partial ? null : round($normalizedTotal, 2));

        return [
            'source_breakdown' => $sourceBreakdown,
            'source_currency_count' => count($sourceBreakdown),
            'source_scalar_amount' => count($sourceBreakdown) === 1 ? array_values($sourceBreakdown)[0] : null,
            'normalized_total' => $normalizedValue,
            'normalized_currency' => $target,
            'normalized_display' => $normalizedValue !== null ? $this->formatMoney($normalizedValue, $target) : null,
            'normalization_meta' => [
                'enabled' => (bool) ($settings['enabled'] ?? false),
                'provider' => (string) ($settings['provider'] ?? 'currencyapi'),
                'rate_policy' => (string) ($settings['rate_policy'] ?? 'historical_locked'),
                'stale' => $stale,
                'partial' => $partial,
                'missing_rate_count' => count($missing),
                'missing_currencies' => $missing,
                'as_of' => end($asOfDates) ?: $date,
                'target_currency' => $target,
                'rows' => $rows,
            ],
        ];
    }

    public function normalizePaymentQuery($query, ?string $targetCurrency = null): array
    {
        $settings = $this->settings();
        $target = $this->normalizeCurrency($targetCurrency ?? $settings['target_currency'] ?? self::DEFAULT_TARGET_CURRENCY, self::DEFAULT_TARGET_CURRENCY);
        $driver = DB::connection()->getDriverName();
        $dateExpression = $driver === 'sqlite'
            ? "date(COALESCE(payments.completed_at, payments.created_at))"
            : "DATE(COALESCE(payments.completed_at, payments.created_at))";
        $currencyExpression = "COALESCE(payments.currency, (SELECT currency_code FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1), '{$target}')";

        $aggregateQuery = clone $query;

        $rows = $aggregateQuery
            ->select(DB::raw("{$dateExpression} as event_date"))
            ->selectRaw("{$currencyExpression} as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupByRaw($dateExpression)
            ->groupByRaw($currencyExpression)
            ->get();

        return $this->normalizeEventRows($rows, $target);
    }

    public function normalizeEventRows(iterable $rows, ?string $targetCurrency = null): array
    {
        $settings = $this->settings();
        $target = $this->normalizeCurrency($targetCurrency ?? $settings['target_currency'] ?? self::DEFAULT_TARGET_CURRENCY, self::DEFAULT_TARGET_CURRENCY);
        $sourceBreakdown = [];
        $normalizedRows = [];
        $normalizedTotal = 0.0;
        $missing = [];
        $stale = false;
        $asOfDates = [];

        foreach ($rows as $row) {
            $currency = $this->normalizeCurrency(data_get($row, 'currency'), $target);
            $amount = round((float) data_get($row, 'amount'), 2);
            $eventDate = (string) data_get($row, 'event_date', now()->toDateString());
            $sourceBreakdown[$currency] = ($sourceBreakdown[$currency] ?? 0.0) + $amount;

            $normalized = $this->normalizeBreakdown([$currency => $amount], Carbon::parse($eventDate), $target);
            $meta = $normalized['normalization_meta'] ?? [];
            $line = ($meta['rows'] ?? [])[0] ?? [];

            if (($meta['partial'] ?? false) === true) {
                $missing[] = $currency;
            } else {
                $normalizedTotal += (float) ($normalized['normalized_total'] ?? 0);
            }

            $stale = $stale || (bool) ($meta['stale'] ?? false);
            $asOfDates[] = (string) ($meta['as_of'] ?? $eventDate);
            $normalizedRows[] = array_merge($line, [
                'event_date' => $eventDate,
            ]);
        }

        ksort($sourceBreakdown);
        sort($asOfDates);
        $missing = array_values(array_unique($missing));
        $partial = count($missing) > 0;
        $normalizedValue = $partial ? null : round($normalizedTotal, 2);

        return [
            'source_breakdown' => $sourceBreakdown,
            'source_currency_count' => count($sourceBreakdown),
            'source_scalar_amount' => count($sourceBreakdown) === 1 ? array_values($sourceBreakdown)[0] : null,
            'normalized_total' => $normalizedValue,
            'normalized_currency' => $target,
            'normalized_display' => $normalizedValue !== null ? $this->formatMoney($normalizedValue, $target) : null,
            'normalization_meta' => [
                'enabled' => (bool) ($settings['enabled'] ?? false),
                'provider' => (string) ($settings['provider'] ?? 'currencyapi'),
                'rate_policy' => (string) ($settings['rate_policy'] ?? 'historical_locked'),
                'stale' => $stale,
                'partial' => $partial,
                'missing_rate_count' => count($missing),
                'missing_currencies' => $missing,
                'as_of' => end($asOfDates) ?: null,
                'target_currency' => $target,
                'rows' => $normalizedRows,
            ],
        ];
    }

    private function resolveRate(string $sourceCurrency, string $targetCurrency, string $date, array $settings): ?ReportingFxRate
    {
        $provider = (string) ($settings['provider'] ?? 'currencyapi');
        $staleDays = max(0, (int) ($settings['stale_days'] ?? 7));
        $fromDate = Carbon::parse($date)->subDays($staleDays)->toDateString();

        return ReportingFxRate::query()
            ->where('provider', $provider)
            ->where('source_currency', $sourceCurrency)
            ->where('target_currency', $targetCurrency)
            ->whereDate('rate_date', '<=', $date)
            ->whereDate('rate_date', '>=', $fromDate)
            ->orderByDesc('rate_date')
            ->first();
    }

    /**
     * @param  array<string, float|int|string|null>  $breakdown
     * @return array<string, float>
     */
    private function normalizeBreakdownInput(array $breakdown): array
    {
        $normalized = [];

        foreach ($breakdown as $currency => $amount) {
            $code = $this->normalizeCurrency($currency, '');
            $numeric = round((float) $amount, 2);

            if ($code === '' || $numeric === 0.0) {
                continue;
            }

            $normalized[$code] = ($normalized[$code] ?? 0.0) + $numeric;
        }

        ksort($normalized);

        return $normalized;
    }

    private function normalizeCurrency(mixed $value, string $fallback): string
    {
        $currency = strtoupper(trim((string) $value));

        if ($currency === '') {
            return strtoupper(trim($fallback));
        }

        return substr($currency, 0, 8);
    }

    private function formatMoney(float $amount, string $currency): string
    {
        return sprintf('%s %s', $currency, number_format($amount, 2));
    }
}
