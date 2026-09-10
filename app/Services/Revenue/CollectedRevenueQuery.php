<?php

namespace App\Services\Revenue;

use App\Models\Payment;
use App\Services\ReportingCurrencyService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class CollectedRevenueQuery
{
    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService
    ) {}

    public function builder(Carbon $from, Carbon $to, int|array|null $platformScope = null): Builder
    {
        return Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) >= ?', [$from->toDateTimeString()])
            ->whereRaw('COALESCE(payments.completed_at, payments.created_at) <= ?', [$to->toDateTimeString()])
            ->when(is_int($platformScope), fn (Builder $query) => $query->where('payments.platform_id', $platformScope))
            ->when(is_array($platformScope), function (Builder $query) use ($platformScope) {
                if (empty($platformScope)) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereIn('payments.platform_id', $platformScope);
            });
    }

    public function total(Carbon $from, Carbon $to, int|array|null $platformScope, string $targetCurrency): array
    {
        $query = $this->builder($from, $to, $platformScope);
        $normalized = $this->reportingCurrencyService->normalizePaymentQuery(clone $query, $targetCurrency, false);

        return [
            'source_breakdown' => $normalized['source_breakdown'],
            'normalized_total' => $normalized['normalized_total'],
            'normalized_currency' => $normalized['normalized_currency'],
            'normalization_meta' => $normalized['normalization_meta'],
            'payments_count' => (int) (clone $query)->count(),
        ];
    }
}
