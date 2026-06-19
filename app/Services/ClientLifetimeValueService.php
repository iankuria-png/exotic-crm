<?php

namespace App\Services;

use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClientLifetimeValueService
{
    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService,
    ) {}

    /**
     * @param  Collection<int, mixed>|array<int, mixed>  $clientIds
     * @return array<int, array{value_usd: float|null, payment_count: int, last_payment_at: ?string, partial: bool, source_breakdown: array<string, float>}>
     */
    public function forClientIds(Collection|array $clientIds): array
    {
        $ids = collect($clientIds)
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $dateExpression = DB::connection()->getDriverName() === 'sqlite'
            ? 'date(COALESCE(payments.completed_at, payments.created_at))'
            : 'DATE(COALESCE(payments.completed_at, payments.created_at))';

        $rows = Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->leftJoin('platforms', 'platforms.id', '=', 'payments.platform_id')
            ->whereIn('payments.client_id', $ids->all())
            ->selectRaw('payments.client_id')
            ->selectRaw("{$dateExpression} as event_date")
            ->selectRaw('payments.platform_id')
            ->selectRaw('platforms.country as platform_country')
            ->selectRaw('platforms.name as platform_name')
            ->selectRaw("COALESCE(payments.currency, platforms.currency_code, 'USD') as currency")
            ->selectRaw('SUM(payments.amount) as amount')
            ->selectRaw('COUNT(*) as payments_count')
            ->selectRaw('MAX(COALESCE(payments.completed_at, payments.created_at)) as last_payment_at')
            ->groupBy('payments.client_id', 'payments.platform_id', 'platforms.country', 'platforms.name')
            ->groupByRaw($dateExpression)
            ->groupByRaw("COALESCE(payments.currency, platforms.currency_code, 'USD')")
            ->get();

        return $rows
            ->groupBy('client_id')
            ->map(function (Collection $clientRows): array {
                $normalized = $this->reportingCurrencyService->normalizeEventRows($clientRows, 'USD', false);
                $partial = (bool) data_get($normalized, 'normalization_meta.partial', false);
                $normalizedTotal = $normalized['normalized_total'] ?? null;

                return [
                    'value_usd' => $normalizedTotal !== null ? round((float) $normalizedTotal, 2) : null,
                    'payment_count' => (int) $clientRows->sum('payments_count'),
                    'last_payment_at' => $clientRows->max('last_payment_at'),
                    'partial' => $partial,
                    'source_breakdown' => $this->floatBreakdown($normalized['source_breakdown'] ?? []),
                ];
            })
            ->all();
    }

    /**
     * @param  array<string, mixed>  $breakdown
     * @return array<string, float>
     */
    private function floatBreakdown(array $breakdown): array
    {
        return collect($breakdown)
            ->mapWithKeys(fn ($amount, string $currency) => [$currency => round((float) $amount, 2)])
            ->all();
    }
}
