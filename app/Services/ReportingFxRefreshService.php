<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use App\Models\ReportingFxRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Daily CurrencyAPI pull of closing rates for every market currency.
 *
 * Reports otherwise only fetch on a cache miss, so a currency could ride on a borrowed
 * prior-date rate for the whole stale window, and a same-day `/latest` fetch is an intraday
 * snapshot rather than the day's close. One USD-base `/historical` call covers every
 * currency for a closed UTC day; the result is stored per currency as source→target.
 *
 * Rates fetched after their day closed are final and never overwritten. Provisional
 * intraday rows inside the stale window are replaced by that day's close.
 */
class ReportingFxRefreshService
{
    public const STATUS_KEY = 'reporting_fx_refresh';

    public const FAILURE_EVENT = 'reporting_fx.daily_refresh_failed';

    // The provider allows ~10 requests per minute and the plan has a small monthly quota.
    private const MAX_CALLS_PER_RUN = 6;

    private const SECONDS_BETWEEN_CALLS = 7;

    private const LOW_QUOTA_REMAINING = 30;

    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService,
        private readonly CurrencyCanonicalizer $currencyCanonicalizer
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function refresh(?string $throughDate = null, bool $dryRun = false): array
    {
        $settings = $this->reportingCurrencyService->settings();
        $target = (string) ($settings['target_currency'] ?? ReportingCurrencyService::DEFAULT_TARGET_CURRENCY);
        $staleDays = max(1, (int) ($settings['stale_days'] ?? 7));
        $yesterday = now('UTC')->subDay()->toDateString();
        $through = $throughDate !== null ? min(Carbon::parse($throughDate)->toDateString(), $yesterday) : $yesterday;
        $currencies = $this->expectedCurrencies($target);
        $result = [
            'started_at' => now()->toIso8601String(),
            'target_currency' => $target,
            'through_date' => $through,
            'currencies' => $currencies,
            'dates_requested' => [],
            'dates_fetched' => [],
            'rows_written' => 0,
            'unsupported_currencies' => [],
            'quota' => null,
            'issues' => [],
            'dry_run' => $dryRun,
        ];

        if (! ($settings['enabled'] ?? false) || ($settings['provider'] ?? 'currencyapi') !== 'currencyapi') {
            $result['status'] = 'skipped';
            $result['issues'][] = 'Reporting FX is disabled or not using CurrencyAPI.';

            return $this->finish($result, false);
        }

        $apiKey = $this->reportingCurrencyService->resolvedApiKey();
        if ($apiKey === '') {
            $result['status'] = 'failed';
            $result['issues'][] = 'No CurrencyAPI key is configured.';

            return $this->finish($result);
        }

        if ($currencies === []) {
            $result['status'] = 'ok';

            return $this->finish($result);
        }

        $baseUrl = rtrim((string) config('services.reporting_fx.base_url', 'https://api.currencyapi.com/v3'), '/');
        $result['quota'] = $this->quota($baseUrl, $apiKey);
        if ($result['quota'] !== null && $result['quota']['remaining'] < self::LOW_QUOTA_REMAINING) {
            $result['issues'][] = "CurrencyAPI quota is low: {$result['quota']['remaining']} of {$result['quota']['total']} monthly requests remain.";
        }

        $result['dates_requested'] = array_slice($this->datesNeedingClose($currencies, $target, $through, $staleDays), 0, self::MAX_CALLS_PER_RUN);

        foreach ($result['dates_requested'] as $index => $date) {
            if ($dryRun) {
                continue;
            }
            if ($index > 0) {
                Sleep::for(self::SECONDS_BETWEEN_CALLS)->seconds();
            }

            $fetched = $this->fetchClose($baseUrl, $apiKey, $date, $target, $currencies);
            if (isset($fetched['error'])) {
                $result['issues'][] = "{$date}: {$fetched['error']}";

                continue;
            }

            $result['dates_fetched'][] = $date;
            $result['rows_written'] += $this->storeClose($date, $target, $fetched['per_target'], $fetched['last_updated_at']);
            foreach (array_diff($currencies, array_keys($fetched['per_target'])) as $missing) {
                $result['unsupported_currencies'][$missing] = true;
            }
        }

        $result['unsupported_currencies'] = array_keys($result['unsupported_currencies']);
        if ($result['unsupported_currencies'] !== []) {
            $result['issues'][] = 'CurrencyAPI returned no rate for: '.implode(', ', $result['unsupported_currencies']).'.';
        }

        $outdated = array_keys(array_filter(
            $this->currencyFreshness($currencies, $target, $staleDays),
            fn (array $row) => $row['state'] === 'outdated'
        ));
        if ($outdated !== [] && ! $dryRun) {
            $result['issues'][] = "No rate within the {$staleDays}-day window for: ".implode(', ', $outdated).'; converted totals for these will show as partial.';
        }

        return $this->finish($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $settings = $this->reportingCurrencyService->settings();
        $target = (string) ($settings['target_currency'] ?? ReportingCurrencyService::DEFAULT_TARGET_CURRENCY);
        $staleDays = max(1, (int) ($settings['stale_days'] ?? 7));
        $stored = IntegrationSetting::query()->where('key', self::STATUS_KEY)->value('value');
        $stored = is_array($stored) ? $stored : [];
        $currencies = $this->currencyFreshness($this->expectedCurrencies($target), $target, $staleDays);

        return [
            'last_run_at' => $stored['last_run_at'] ?? null,
            'last_success_at' => $stored['last_success_at'] ?? null,
            'status' => $stored['status'] ?? 'never_run',
            'issues' => $stored['issues'] ?? [],
            'quota' => $stored['quota'] ?? null,
            'stale_days' => $staleDays,
            'currencies' => $currencies,
            'outdated_count' => count(array_filter($currencies, fn (array $row) => $row['state'] === 'outdated')),
        ];
    }

    /**
     * Canonical non-target currencies used by any market or by recent payments.
     *
     * @return array<int, string>
     */
    public function expectedCurrencies(string $target): array
    {
        $rows = DB::table('platforms')
            ->select('currency_code as currency', 'country', 'name')
            ->whereNotNull('currency_code')
            ->get()
            ->concat(
                DB::table('payments')
                    ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
                    ->select('payments.currency', 'platforms.country', 'platforms.name')
                    ->whereNotNull('payments.currency')
                    ->where('payments.created_at', '>=', now()->subDays(35))
                    ->distinct()
                    ->get()
            );

        $codes = [];
        foreach ($rows as $row) {
            $resolved = $this->currencyCanonicalizer->resolve($row->currency, [
                'platform_country' => $row->country,
                'platform_name' => $row->name,
            ]);
            if ($resolved['code'] !== null && $resolved['code'] !== $target) {
                $codes[$resolved['code']] = true;
            }
        }

        $codes = array_keys($codes);
        sort($codes);

        return $codes;
    }

    /**
     * @param  array<int, string>  $currencies
     * @return array<string, array<string, mixed>>
     */
    private function currencyFreshness(array $currencies, string $target, int $staleDays): array
    {
        if ($currencies === []) {
            return [];
        }

        $latest = ReportingFxRate::query()
            ->where('provider', 'currencyapi')
            ->where('target_currency', $target)
            ->whereIn('source_currency', $currencies)
            ->groupBy('source_currency')
            ->selectRaw('source_currency, MAX(rate_date) as latest_rate_date, MAX(fetched_at) as latest_fetched_at')
            ->get()
            ->keyBy('source_currency');

        $today = Carbon::parse(now('UTC')->toDateString());
        $rows = [];
        foreach ($currencies as $currency) {
            $row = $latest->get($currency);
            $rateDate = $row?->latest_rate_date ? Carbon::parse($row->latest_rate_date)->toDateString() : null;
            $age = $rateDate !== null ? (int) Carbon::parse($rateDate)->diffInDays($today) : null;
            $rows[$currency] = [
                'currency' => $currency,
                'latest_rate_date' => $rateDate,
                'latest_fetched_at' => $row?->latest_fetched_at ? Carbon::parse($row->latest_fetched_at)->toIso8601String() : null,
                'age_days' => $age,
                // Today's payments rely on rates up to stale_days old; older than that is a gap.
                'state' => $age === null || $age > $staleDays ? 'outdated' : ($age <= 1 ? 'current' : 'aging'),
            ];
        }

        return $rows;
    }

    /**
     * Closed days in the stale window, newest first, where any currency lacks a final close.
     *
     * @param  array<int, string>  $currencies
     * @return array<int, string>
     */
    private function datesNeedingClose(array $currencies, string $target, string $through, int $staleDays): array
    {
        $from = Carbon::parse($through)->subDays($staleDays - 1)->toDateString();
        $final = [];
        ReportingFxRate::query()
            ->where('provider', 'currencyapi')
            ->where('target_currency', $target)
            ->whereIn('source_currency', $currencies)
            ->whereDate('rate_date', '>=', $from)
            ->whereDate('rate_date', '<=', $through)
            ->get(['source_currency', 'rate_date', 'fetched_at'])
            ->each(function (ReportingFxRate $rate) use (&$final) {
                if ($this->isFinal($rate)) {
                    $final[$rate->rate_date->toDateString()][$rate->source_currency] = true;
                }
            });

        $dates = [];
        for ($date = Carbon::parse($through); $date->toDateString() >= $from; $date->subDay()) {
            if (count($final[$date->toDateString()] ?? []) < count($currencies)) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates;
    }

    /**
     * A rate is final once it was fetched after its UTC day closed.
     */
    private function isFinal(ReportingFxRate $rate): bool
    {
        return $rate->fetched_at !== null
            && $rate->fetched_at->copy()->utc()->toDateString() > $rate->rate_date->toDateString();
    }

    /**
     * @param  array<int, string>  $currencies
     * @return array{per_target: array<string, float>, last_updated_at: string|null}|array{error: string}
     */
    private function fetchClose(string $baseUrl, string $apiKey, string $date, string $target, array $currencies): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['apikey' => $apiKey])
                ->get("{$baseUrl}/historical", [
                    'date' => $date,
                    'base_currency' => $target,
                    'currencies' => implode(',', $currencies),
                ]);
        } catch (\Throwable $e) {
            return ['error' => 'CurrencyAPI request failed: '.class_basename($e)];
        }

        if (! $response->successful()) {
            return ['error' => "CurrencyAPI responded with HTTP {$response->status()}."];
        }

        $perTarget = [];
        foreach ((array) $response->json('data') as $code => $entry) {
            $value = (float) ($entry['value'] ?? 0);
            if ($value > 0 && in_array($code, $currencies, true)) {
                $perTarget[$code] = $value;
            }
        }

        if ($perTarget === []) {
            return ['error' => 'CurrencyAPI returned no usable rates.'];
        }

        return ['per_target' => $perTarget, 'last_updated_at' => $response->json('meta.last_updated_at')];
    }

    /**
     * @param  array<string, float>  $perTarget  units of each currency per one target unit
     */
    private function storeClose(string $date, string $target, array $perTarget, ?string $lastUpdatedAt): int
    {
        $existing = ReportingFxRate::query()
            ->where('provider', 'currencyapi')
            ->where('target_currency', $target)
            ->whereDate('rate_date', $date)
            ->whereIn('source_currency', array_keys($perTarget))
            ->get()
            ->keyBy('source_currency');

        $written = 0;
        foreach ($perTarget as $currency => $value) {
            $current = $existing->get($currency);
            if ($current && $this->isFinal($current)) {
                continue;
            }

            $values = [
                'rate' => round(1 / $value, 10),
                'fetched_at' => now(),
                'metadata' => [
                    'source' => 'scheduled_daily_close',
                    'endpoint' => 'historical',
                    'base_currency' => $target,
                    'units_per_base' => $value,
                    'provider_last_updated_at' => $lastUpdatedAt,
                    'replaced_provisional' => $current !== null,
                    'fetched_at' => now()->toIso8601String(),
                ],
            ];

            if ($current) {
                $current->update($values);
            } else {
                ReportingFxRate::query()->create($values + [
                    'provider' => 'currencyapi',
                    'source_currency' => $currency,
                    'target_currency' => $target,
                    'rate_date' => $date,
                ]);
            }
            $written++;
        }

        return $written;
    }

    /**
     * @return array{used: int, total: int, remaining: int}|null
     */
    private function quota(string $baseUrl, string $apiKey): ?array
    {
        try {
            $response = Http::timeout(8)->withHeaders(['apikey' => $apiKey])->get("{$baseUrl}/status");
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $month = (array) $response->json('quotas.month');

        return [
            'used' => (int) ($month['used'] ?? 0),
            'total' => (int) ($month['total'] ?? 0),
            'remaining' => (int) ($month['remaining'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finish(array $result, bool $record = true): array
    {
        $result['status'] ??= $result['issues'] === [] ? 'ok' : ($result['dates_fetched'] === [] && $result['dates_requested'] !== [] ? 'failed' : 'degraded');
        if ($result['issues'] !== [] && $result['status'] === 'ok') {
            $result['status'] = 'degraded';
        }
        if ($result['dry_run'] || ! $record) {
            return $result;
        }

        $stored = IntegrationSetting::query()->where('key', self::STATUS_KEY)->value('value');
        $stored = is_array($stored) ? $stored : [];
        $now = now()->toIso8601String();
        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::STATUS_KEY],
            ['value' => [
                'last_run_at' => $now,
                'last_success_at' => $result['status'] === 'ok' ? $now : ($stored['last_success_at'] ?? null),
                'status' => $result['status'],
                'issues' => $result['issues'],
                'quota' => $result['quota'],
                'dates_fetched' => $result['dates_fetched'],
                'rows_written' => $result['rows_written'],
            ]]
        );

        // Error level reaches Settings → Error Logs; warnings would only sit in the file log.
        if ($result['issues'] !== []) {
            Log::error(self::FAILURE_EVENT, [
                'status' => $result['status'],
                'issues' => $result['issues'],
                'dates_requested' => $result['dates_requested'],
                'dates_fetched' => $result['dates_fetched'],
            ]);
        } else {
            Log::info('reporting_fx.daily_refresh_ok', [
                'dates_fetched' => $result['dates_fetched'],
                'rows_written' => $result['rows_written'],
            ]);
        }

        return $result;
    }
}
