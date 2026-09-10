<?php

namespace App\Services\Forecast;

use App\Models\Client;
use App\Models\Payment;
use App\Services\ReportingCurrencyService;
use App\Support\SignupSource;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class SignupSourceConversionService
{
    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService
    ) {}

    /** Quote a short identifier-like value for safe inlining into a GROUP BY expression. */
    private function sqlLiteral(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9_]/', '', $value);

        return "'".($clean !== '' ? $clean : 'unknown')."'";
    }

    public function summarize(Carbon $from, Carbon $to, int|array|null $platformScope, string $targetCurrency): array
    {
        // MariaDB does not resolve a GROUP BY alias back to its expression for the
        // ONLY_FULL_GROUP_BY dependency check, and it cannot match two separate bind
        // placeholders either. So group by the expression itself with the literal
        // inlined - the shape ChurnAggregatorService already uses on this database.
        $existing = $this->sqlLiteral(SignupSource::EXISTING);
        $currency = $this->sqlLiteral($targetCurrency);

        $signupRows = Client::query()
            ->selectRaw("COALESCE(signup_source, {$existing}) as signup_source")
            ->selectRaw('COUNT(*) as signups')
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when(is_int($platformScope), fn (Builder $query) => $query->where('platform_id', $platformScope))
            ->when(is_array($platformScope), function (Builder $query) use ($platformScope) {
                empty($platformScope)
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('platform_id', $platformScope);
            })
            // Group by the select alias, never by a repeated raw expression carrying a
            // bind placeholder: MySQL matches GROUP BY to SELECT on the parse tree, and
            // two separate `?` markers are not provably equal, so it reports the bare
            // column as ungrouped under ONLY_FULL_GROUP_BY.
            ->groupByRaw("COALESCE(signup_source, {$existing})")
            ->get();

        $firstPaidSubquery = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->whereNotNull('payments.client_id')
            ->selectRaw('payments.client_id')
            ->selectRaw('MIN(COALESCE(payments.completed_at, payments.created_at)) as first_paid_at')
            ->groupBy('payments.client_id');

        $conversionRows = Client::query()
            ->joinSub($firstPaidSubquery, 'first_paid', 'first_paid.client_id', '=', 'clients.id')
            ->leftJoin('payments', function ($join) {
                $join->on('payments.client_id', '=', 'clients.id')
                    ->whereRaw('COALESCE(payments.completed_at, payments.created_at) = first_paid.first_paid_at');
            })
            // Join platforms rather than correlating a subquery on clients.platform_id:
            // under MySQL's ONLY_FULL_GROUP_BY that column is neither grouped nor
            // aggregated, which is a 1055 on prod and silently fine on SQLite.
            ->leftJoin('platforms', 'platforms.id', '=', 'clients.platform_id')
            ->selectRaw("COALESCE(clients.signup_source, {$existing}) as signup_source")
            ->selectRaw('COUNT(DISTINCT clients.id) as converted')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, {$currency}) as currency")
            ->selectRaw('SUM(COALESCE(payments.amount, 0)) as amount')
            ->whereBetween('clients.created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->whereBetween('first_paid.first_paid_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when(is_int($platformScope), fn (Builder $query) => $query->where('clients.platform_id', $platformScope))
            ->when(is_array($platformScope), function (Builder $query) use ($platformScope) {
                empty($platformScope)
                    ? $query->whereRaw('1 = 0')
                    : $query->whereIn('clients.platform_id', $platformScope);
            })
            ->groupByRaw(
                "COALESCE(clients.signup_source, {$existing}), ".
                "COALESCE(payments.currency, platforms.currency_code, {$currency})"
            )
            ->get();

        $convertedBySource = [];
        $amountRows = [];
        foreach ($conversionRows as $row) {
            $source = SignupSource::normalize($row->signup_source);
            $convertedBySource[$source] = ($convertedBySource[$source] ?? 0) + (int) $row->converted;
            $amountRows[] = (object) [
                'event_date' => $to->toDateString(),
                'currency' => $row->currency,
                'amount' => (float) $row->amount,
            ];
        }

        $normalized = $this->reportingCurrencyService->normalizeEventRows($amountRows, $targetCurrency, false);
        $signupsBySource = [];
        foreach ($signupRows as $row) {
            $source = SignupSource::normalize($row->signup_source);
            $signupsBySource[$source] = ($signupsBySource[$source] ?? 0) + (int) $row->signups;
        }

        $sources = [];
        foreach (SignupSource::LABELS as $key => $label) {
            $signups = (int) ($signupsBySource[$key] ?? 0);
            $converted = (int) ($convertedBySource[$key] ?? 0);
            $sources[] = [
                'key' => $key,
                'label' => $label,
                'signups' => $signups,
                'converted' => $converted,
                'rate' => $signups > 0 ? round(($converted / $signups) * 100, 1) : 0.0,
            ];
        }

        $totalSignups = array_sum($signupsBySource);
        $totalConverted = array_sum($convertedBySource);

        return [
            'signups' => (int) $totalSignups,
            'converted' => (int) $totalConverted,
            'rate' => $totalSignups > 0 ? round(($totalConverted / $totalSignups) * 100, 1) : 0.0,
            'avg_ticket' => $totalConverted > 0 && $normalized['normalized_total'] !== null
                ? round((float) $normalized['normalized_total'] / $totalConverted, 2)
                : 0.0,
            'sources' => $sources,
            'normalization_meta' => $normalized['normalization_meta'],
        ];
    }
}
