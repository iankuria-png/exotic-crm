<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use App\Models\ReportingFxRate;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ReportingCurrencyService
{
    public const SETTINGS_KEY = 'reporting_currency';
    public const MODE_NATIVE = 'native';
    public const MODE_FLAT = 'flat';
    public const DEFAULT_TARGET_CURRENCY = 'USD';

    public function __construct(
        private readonly CurrencyCanonicalizer $currencyCanonicalizer
    ) {
    }

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

        $apiKeyConfigured = !empty($stored['api_key_encrypted']) || !empty(config('services.reporting_fx.api_key'));

        return [
            'enabled' => (bool) ($stored['enabled'] ?? config('services.reporting_fx.enabled', false)),
            'target_currency' => $targetCurrency,
            'provider' => $provider !== '' ? $provider : 'currencyapi',
            'allow_user_override' => (bool) ($stored['allow_user_override'] ?? config('services.reporting_fx.allow_user_override', true)),
            'stale_days' => max(0, (int) ($stored['stale_days'] ?? config('services.reporting_fx.stale_days', 7))),
            'rate_policy' => (string) ($stored['rate_policy'] ?? 'historical_locked'),
            'fallback_behavior' => (string) ($stored['fallback_behavior'] ?? 'partial_with_native'),
            'last_checked_at' => $stored['last_checked_at'] ?? null,
            'api_key_configured' => $apiKeyConfigured,
            'health' => $stored['health'] ?? [
                'status' => $apiKeyConfigured ? 'configured' : 'missing_api_key',
                'message' => $apiKeyConfigured
                    ? 'FX provider credentials are configured.'
                    : 'FX provider API key is not configured; cached rates can still be used.',
            ],
        ];
    }

    public function updateSettings(array $input, ?int $userId = null): array
    {
        $current = $this->settings();

        $stored = IntegrationSetting::query()
            ->where('key', self::SETTINGS_KEY)
            ->value('value');
        $stored = is_array($stored) ? $stored : [];

        $next = array_merge($stored, [
            'enabled' => array_key_exists('enabled', $input) ? (bool) $input['enabled'] : $current['enabled'],
            'target_currency' => $this->normalizeCurrency($input['target_currency'] ?? $current['target_currency'], self::DEFAULT_TARGET_CURRENCY),
            'provider' => trim((string) ($input['provider'] ?? $current['provider'])) ?: 'currencyapi',
            'allow_user_override' => array_key_exists('allow_user_override', $input) ? (bool) $input['allow_user_override'] : $current['allow_user_override'],
            'stale_days' => max(0, (int) ($input['stale_days'] ?? $current['stale_days'])),
            'rate_policy' => (string) ($input['rate_policy'] ?? $current['rate_policy']),
            'fallback_behavior' => (string) ($input['fallback_behavior'] ?? $current['fallback_behavior']),
        ]);

        if (array_key_exists('api_key', $input) && trim((string) $input['api_key']) !== '') {
            $next['api_key_encrypted'] = Crypt::encryptString(trim((string) $input['api_key']));
        } else {
            $next['api_key_encrypted'] = $stored['api_key_encrypted'] ?? '';
        }

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            [
                'value' => $next,
                'updated_by' => $userId,
            ]
        );

        return $this->settings();
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
    public function normalizeBreakdown(array $breakdown, ?CarbonInterface $eventDate = null, ?string $targetCurrency = null, bool $allowLiveFetch = true): array
    {
        $settings = $this->settings();
        $target = $this->normalizeCurrency($targetCurrency ?? $settings['target_currency'] ?? self::DEFAULT_TARGET_CURRENCY, self::DEFAULT_TARGET_CURRENCY);
        $date = $eventDate ? Carbon::instance($eventDate)->toDateString() : now()->toDateString();
        [$sourceBreakdown, $conversionBreakdown, $unresolvedRows, $missing, $missingDetails, $aliasRows] = $this->prepareBreakdownForNormalization($breakdown);
        $rows = [];
        $normalizedTotal = 0.0;
        $stale = false;
        $asOfDates = [];

        foreach ($conversionBreakdown as $currency => $amount) {
            if ($currency === $target) {
                $rate = 1.0;
                $rateDate = $date;
                $provider = 'identity';
                $rateStale = false;
            } else {
                $snapshot = $this->resolveRate($currency, $target, $date, $settings, $allowLiveFetch);

                if (!$snapshot) {
                    $missing[] = $currency;
                    $missingDetails[$currency] ??= 'No cached or provider FX rate is available.';
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
        $missing = array_values(array_unique($missing));
        ksort($missingDetails);
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
                'rows' => array_merge($unresolvedRows, $rows),
                'unresolved_rows' => $unresolvedRows,
                'currency_aliases' => $aliasRows,
                'missing_currency_reasons' => $missingDetails,
            ],
        ];
    }

    public function normalizePaymentQuery($query, ?string $targetCurrency = null, bool $allowLiveFetch = true): array
    {
        $settings = $this->settings();
        $target = $this->normalizeCurrency($targetCurrency ?? $settings['target_currency'] ?? self::DEFAULT_TARGET_CURRENCY, self::DEFAULT_TARGET_CURRENCY);
        $driver = DB::connection()->getDriverName();
        $dateExpression = $driver === 'sqlite'
            ? "date(COALESCE(payments.completed_at, payments.created_at))"
            : "DATE(COALESCE(payments.completed_at, payments.created_at))";
        $currencyExpression = "COALESCE(payments.currency, (SELECT currency_code FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1), '{$target}')";
        $platformCountryExpression = "(SELECT country FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1)";
        $platformNameExpression = "(SELECT name FROM platforms WHERE platforms.id = payments.platform_id LIMIT 1)";

        $aggregateQuery = clone $query;

        $rows = $aggregateQuery
            ->select(DB::raw("{$dateExpression} as event_date"))
            ->selectRaw('payments.platform_id as platform_id')
            ->selectRaw("{$platformCountryExpression} as platform_country")
            ->selectRaw("{$platformNameExpression} as platform_name")
            ->selectRaw("{$currencyExpression} as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupByRaw($dateExpression)
            ->groupBy('payments.platform_id')
            ->groupByRaw($platformCountryExpression)
            ->groupByRaw($platformNameExpression)
            ->groupByRaw($currencyExpression)
            ->get();

        return $this->normalizeEventRows($rows, $target, $allowLiveFetch);
    }

    public function normalizeEventRows(iterable $rows, ?string $targetCurrency = null, bool $allowLiveFetch = true): array
    {
        $settings = $this->settings();
        $target = $this->normalizeCurrency($targetCurrency ?? $settings['target_currency'] ?? self::DEFAULT_TARGET_CURRENCY, self::DEFAULT_TARGET_CURRENCY);
        $sourceBreakdown = [];
        $normalizedRows = [];
        $normalizedTotal = 0.0;
        $missing = [];
        $missingDetails = [];
        $aliasRows = [];
        $stale = false;
        $asOfDates = [];

        foreach ($rows as $row) {
            $originalCurrency = $this->normalizeCurrency(data_get($row, 'currency'), $target);
            $amount = round((float) data_get($row, 'amount'), 2);
            $eventDate = (string) data_get($row, 'event_date', now()->toDateString());
            $sourceBreakdown[$originalCurrency] = ($sourceBreakdown[$originalCurrency] ?? 0.0) + $amount;

            $resolution = $this->currencyCanonicalizer->resolve($originalCurrency, [
                'platform_id' => data_get($row, 'platform_id'),
                'platform_country' => data_get($row, 'platform_country'),
                'platform_name' => data_get($row, 'platform_name'),
            ]);

            $currency = $resolution['code'];

            if ($currency === null) {
                $missingKey = $resolution['original'] !== '' ? $resolution['original'] : $originalCurrency;
                $missing[] = $missingKey;
                $missingDetails[$missingKey] = $resolution['reason'] ?? 'Currency could not be resolved.';
                $normalizedRows[] = [
                    'event_date' => $eventDate,
                    'source_currency' => $originalCurrency,
                    'source_amount' => $amount,
                    'rate_source_currency' => null,
                    'rate' => null,
                    'rate_date' => null,
                    'normalized_amount' => null,
                    'stale' => false,
                    'resolution_status' => $resolution['status'],
                    'resolution_reason' => $resolution['reason'],
                ];
                continue;
            }

            if ($resolution['status'] === 'canonicalized') {
                $aliasRows[] = [
                    'source_currency' => $originalCurrency,
                    'canonical_currency' => $currency,
                    'reason' => $resolution['reason'],
                    'platform_id' => data_get($row, 'platform_id'),
                ];
            }

            $normalized = $this->normalizeBreakdown([$currency => $amount], Carbon::parse($eventDate), $target, $allowLiveFetch);
            $meta = $normalized['normalization_meta'] ?? [];
            $line = ($meta['rows'] ?? [])[0] ?? [];

            if (($meta['partial'] ?? false) === true) {
                $missing[] = $currency;
                foreach (($meta['missing_currency_reasons'] ?? []) as $missingCurrency => $reason) {
                    $missingDetails[$missingCurrency] = $reason;
                }
            } else {
                $normalizedTotal += (float) ($normalized['normalized_total'] ?? 0);
            }

            $stale = $stale || (bool) ($meta['stale'] ?? false);
            $asOfDates[] = (string) ($meta['as_of'] ?? $eventDate);
            $normalizedRows[] = array_merge($line, [
                'event_date' => $eventDate,
                'source_currency' => $originalCurrency,
                'rate_source_currency' => $currency,
                'resolution_status' => $resolution['status'],
                'resolution_reason' => $resolution['reason'],
            ]);
        }

        ksort($sourceBreakdown);
        sort($asOfDates);
        $missing = array_values(array_unique($missing));
        ksort($missingDetails);
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
                'currency_aliases' => $aliasRows,
                'missing_currency_reasons' => $missingDetails,
            ],
        ];
    }

    public function resolvedApiKey(): string
    {
        $stored = IntegrationSetting::query()
            ->where('key', self::SETTINGS_KEY)
            ->value('value');
        $stored = is_array($stored) ? $stored : [];
        $encrypted = (string) ($stored['api_key_encrypted'] ?? '');

        if ($encrypted !== '') {
            try {
                return Crypt::decryptString($encrypted);
            } catch (\Throwable) {
            }
        }

        return (string) config('services.reporting_fx.api_key', '');
    }

    public function testProvider(): array
    {
        $apiKey = $this->resolvedApiKey();

        if ($apiKey === '') {
            return [
                'ok' => false,
                'error' => 'No API key configured. Enter your CurrencyAPI key in settings.',
            ];
        }

        $baseUrl = rtrim((string) config('services.reporting_fx.base_url', 'https://api.currencyapi.com/v3'), '/');

        try {
            $response = Http::timeout(8)
                ->withHeaders(['apikey' => $apiKey])
                ->get("{$baseUrl}/status");

            if (!$response->successful()) {
                return [
                    'ok' => false,
                    'error' => "CurrencyAPI responded with HTTP {$response->status()}.",
                ];
            }

            $body = $response->json();
            $month = $body['quotas']['month'] ?? [];

            return [
                'ok' => true,
                'plan' => $body['account_id'] ?? 'unknown',
                'quotas_used' => (int) ($month['used'] ?? 0),
                'quotas_total' => (int) ($month['total'] ?? 0),
                'quotas_remaining' => (int) ($month['remaining'] ?? 0),
                'checked_at' => now()->toIso8601String(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Connection failed: ' . $e->getMessage(),
            ];
        }
    }

    private function resolveRate(string $sourceCurrency, string $targetCurrency, string $date, array $settings, bool $allowLiveFetch = true): ?ReportingFxRate
    {
        $provider = (string) ($settings['provider'] ?? 'currencyapi');
        $staleDays = max(0, (int) ($settings['stale_days'] ?? 7));
        $fromDate = Carbon::parse($date)->subDays($staleDays)->toDateString();

        // Manual overrides take precedence: exact date match for the (source, target) pair.
        $manual = ReportingFxRate::query()
            ->where('provider', 'manual')
            ->where('source_currency', $sourceCurrency)
            ->where('target_currency', $targetCurrency)
            ->whereDate('rate_date', $date)
            ->first();

        if ($manual) {
            return $manual;
        }

        $cached = ReportingFxRate::query()
            ->where('provider', $provider)
            ->where('source_currency', $sourceCurrency)
            ->where('target_currency', $targetCurrency)
            ->whereDate('rate_date', '<=', $date)
            ->whereDate('rate_date', '>=', $fromDate)
            ->orderByDesc('rate_date')
            ->first();

        if ($cached) {
            return $cached;
        }

        // Cache miss: attempt a live fetch from CurrencyAPI if a key is available.
        if ($allowLiveFetch && $provider === 'currencyapi') {
            return $this->fetchAndCacheLiveRate($sourceCurrency, $targetCurrency, $date);
        }

        return null;
    }

    private function fetchAndCacheLiveRate(string $sourceCurrency, string $targetCurrency, string $date): ?ReportingFxRate
    {
        $apiKey = $this->resolvedApiKey();

        if ($apiKey === '') {
            return null;
        }

        $baseUrl = rtrim((string) config('services.reporting_fx.base_url', 'https://api.currencyapi.com/v3'), '/');
        $today = now()->toDateString();
        $endpoint = $date === $today ? "{$baseUrl}/latest" : "{$baseUrl}/historical";

        try {
            $params = [
                'base_currency' => $sourceCurrency,
                'currencies' => $targetCurrency,
            ];

            if ($date !== $today) {
                $params['date'] = $date;
            }

            $response = Http::timeout(5)
                ->withHeaders(['apikey' => $apiKey])
                ->get($endpoint, $params);

            if (!$response->successful()) {
                return null;
            }

            $rateValue = (float) ($response->json("data.{$targetCurrency}.value") ?? 0.0);

            if ($rateValue <= 0.0) {
                return null;
            }

            return ReportingFxRate::query()->updateOrCreate(
                [
                    'provider' => 'currencyapi',
                    'source_currency' => $sourceCurrency,
                    'target_currency' => $targetCurrency,
                    'rate_date' => $date,
                ],
                [
                    'rate' => $rateValue,
                    'fetched_at' => now(),
                    'metadata' => ['source' => 'live_fetch', 'fetched_at' => now()->toIso8601String()],
                ]
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, float|int|string|null>  $breakdown
     * @return array{
     *     0: array<string, float>,
     *     1: array<string, float>,
     *     2: array<int, array<string, mixed>>,
     *     3: array<int, string>,
     *     4: array<string, string>,
     *     5: array<int, array<string, mixed>>
     * }
     */
    private function prepareBreakdownForNormalization(array $breakdown): array
    {
        $sourceBreakdown = [];
        $conversionBreakdown = [];
        $unresolvedRows = [];
        $missing = [];
        $missingDetails = [];
        $aliasRows = [];

        foreach ($breakdown as $currency => $amount) {
            $numeric = round((float) $amount, 2);
            $original = $this->normalizeCurrency($currency, '');

            if ($original === '' || $numeric === 0.0) {
                continue;
            }

            $sourceBreakdown[$original] = ($sourceBreakdown[$original] ?? 0.0) + $numeric;
            $resolution = $this->currencyCanonicalizer->resolve($original);

            if ($resolution['code'] === null) {
                $missingKey = $resolution['original'] !== '' ? $resolution['original'] : $original;
                $missing[] = $missingKey;
                $missingDetails[$missingKey] = $resolution['reason'] ?? 'Currency could not be resolved.';
                $unresolvedRows[] = [
                    'source_currency' => $original,
                    'source_amount' => $numeric,
                    'rate_source_currency' => null,
                    'rate' => null,
                    'rate_date' => null,
                    'normalized_amount' => null,
                    'stale' => false,
                    'resolution_status' => $resolution['status'],
                    'resolution_reason' => $resolution['reason'],
                ];
                continue;
            }

            $canonical = $resolution['code'];
            $conversionBreakdown[$canonical] = ($conversionBreakdown[$canonical] ?? 0.0) + $numeric;

            if ($resolution['status'] === 'canonicalized') {
                $aliasRows[] = [
                    'source_currency' => $original,
                    'canonical_currency' => $canonical,
                    'reason' => $resolution['reason'],
                ];
            }
        }

        ksort($sourceBreakdown);
        ksort($conversionBreakdown);
        ksort($missingDetails);

        return [
            $sourceBreakdown,
            $conversionBreakdown,
            $unresolvedRows,
            array_values(array_unique($missing)),
            $missingDetails,
            $aliasRows,
        ];
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
