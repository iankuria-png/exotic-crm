<?php

namespace App\Services\Ai;

use App\Models\Payment;
use App\Services\CurrencyCanonicalizer;
use Carbon\Carbon;
use InvalidArgumentException;

/** Original payment amounts; never fetches FX or changes a business record. */
class NativeScorecardService
{
    public function __construct(private readonly CurrencyCanonicalizer $currencies) {}

    public function build(?array $platformIds, array $period, string $source = 'snapshot'): array
    {
        $dates = [];
        foreach (['from', 'to', 'prior_from', 'prior_to'] as $key) {
            $value = $period[$key] ?? null;
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                throw new InvalidArgumentException('Scorecard dates are unavailable.');
            }
            $date = Carbon::createFromFormat('!Y-m-d', $value);
            if ($date->toDateString() !== $value) {
                throw new InvalidArgumentException('Invalid scorecard date.');
            }
            $dates[$key] = $date;
        }
        $days = $dates['from']->diffInDays($dates['to']) + 1;
        if ($dates['to']->lt($dates['from']) || $days > 366
            || ! $dates['prior_to']->copy()->addDay()->equalTo($dates['from'])
            || $dates['prior_from']->copy()->addDays($days - 1)->toDateString() !== $period['prior_to']) {
            throw new InvalidArgumentException('Invalid scorecard comparison window.');
        }

        // Preserve the scorecard's established wall-clock SQL dates (not the
        // UTC period_start/end instants stored on Briefing).
        $event = 'COALESCE(payments.completed_at, payments.created_at)';
        $split = $dates['from']->toDateTimeString();
        $rows = Payment::query()->reportableSuccessful()->excludingWalletTopups()
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->leftJoin('deals', 'deals.id', '=', 'payments.deal_id')
            ->whereRaw("$event >= ?", [$dates['prior_from']->toDateTimeString()])
            ->whereRaw("$event <= ?", [$dates['to']->copy()->endOfDay()->toDateTimeString()])
            ->when($platformIds !== null, fn ($q) => $q->whereIn('payments.platform_id', $platformIds))
            ->selectRaw('payments.platform_id, platforms.name, platforms.country, platforms.currency_code, deals.assigned_to')
            ->selectRaw("COALESCE(NULLIF(TRIM(payments.currency), ''), platforms.currency_code) as source_currency")
            ->selectRaw("CASE WHEN $event >= ? THEN 'current' ELSE 'prior' END as period_key", [$split])
            ->selectRaw('SUM(payments.amount) as amount')
            ->groupBy('payments.platform_id', 'platforms.name', 'platforms.country', 'platforms.currency_code', 'deals.assigned_to')
            ->groupByRaw("COALESCE(NULLIF(TRIM(payments.currency), ''), platforms.currency_code)")
            ->groupByRaw("CASE WHEN $event >= ? THEN 'current' ELSE 'prior' END", [$split])
            ->get();

        $totals = $markets = $members = [];
        foreach ($rows as $row) {
            $resolved = $this->currencies->resolve($row->source_currency, [
                'platform_id' => $row->platform_id,
                'platform_name' => $row->name,
                'platform_country' => $row->country,
            ]);
            $code = $resolved['code'];
            $key = $code ?? 'unresolved:'.$row->platform_id.':'.($resolved['original'] ?: 'blank');
            $label = $code ?? ($resolved['original'] ?: 'Unknown currency').' ('.($row->name ?: 'Unassigned market').')';
            $seed = ['key' => $key, 'currency' => $label, 'unresolved' => $code === null, 'current' => 0.0, 'prior' => 0.0];
            $totals[$key] ??= $seed;
            $totals[$key][$row->period_key] += (float) $row->amount;
            $marketId = (int) $row->platform_id;
            $markets[$marketId] ??= ['platform_id' => $marketId, 'name' => $row->name ?: 'Unassigned market', 'amounts' => []];
            $markets[$marketId]['amounts'][$key] ??= $seed;
            $markets[$marketId]['amounts'][$key][$row->period_key] += (float) $row->amount;
            if ($row->assigned_to) {
                $memberId = (int) $row->assigned_to;
                $members[$memberId] ??= ['user_id' => $memberId, 'amounts' => []];
                $members[$memberId]['amounts'][$key] ??= $seed;
                $members[$memberId]['amounts'][$key][$row->period_key] += (float) $row->amount;
            }
        }

        $compare = function (array $amounts) use ($days): array {
            ksort($amounts);

            return array_values(array_map(static function (array $row) use ($days): array {
                $current = round($row['current'], 2);
                $prior = round($row['prior'], 2);

                return array_merge($row, [
                    'current' => $current, 'prior' => $prior,
                    'delta' => round($current - $prior, 2),
                    'delta_percent' => $prior != 0.0 ? round(($current - $prior) / abs($prior) * 100, 1) : null,
                    'daily' => round($current / $days, 2),
                    'prior_daily' => round($prior / $days, 2),
                ]);
            }, $amounts));
        };
        $withAmounts = static function (array $row) use ($compare): array {
            $row['amounts'] = $compare($row['amounts']);

            return $row;
        };

        return [
            'version' => 1, 'status' => 'ready', 'source' => $source,
            'captured_at' => now()->toIso8601String(), 'period' => $period,
            'currencies' => $compare($totals),
            'markets' => array_values(array_map($withAmounts, $markets)),
            'members' => array_values(array_map($withAmounts, $members)),
        ];
    }
}
