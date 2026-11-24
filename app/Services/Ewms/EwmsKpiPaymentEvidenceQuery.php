<?php

namespace App\Services\Ewms;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use RuntimeException;

class EwmsKpiPaymentEvidenceQuery
{
    public const COLUMNS = ['payment_id', 'crm_user_id', 'platform_id', 'recorded_at', 'amount', 'currency', 'transaction_reference', 'status', 'reconciliation_state', 'resolution_code', 'is_reportable_success'];

    public function __construct(private readonly EwmsEvidenceConnection $database) {}

    public function recentForPlatforms(array $platformIds, string $from, string $to, int $limit): array
    {
        return $this->database->connection()->transaction(function () use ($platformIds, $from, $to, $limit): array {
            $query = $this->period($this->forPlatforms($platformIds), $from, $to);
            $this->assertResolvedCurrencies($query);
            $rows = $this->records($query)->orderByDesc('recorded_at')->orderByDesc('payment_id')->limit($limit + 1)->get();

            return ['data' => $rows->take($limit)->map(fn ($row) => $this->record($row))->all(), 'meta' => ['truncated' => $rows->count() > $limit]];
        });
    }

    public function byPaymentIdsForPlatforms(array $platformIds, array $paymentIds): array
    {
        return $this->database->connection()->transaction(function () use ($platformIds, $paymentIds): array {
            $query = $this->forPlatforms($platformIds)->whereIn('payment_id', $paymentIds);
            $this->assertResolvedCurrencies($query);
            $rows = $this->records($query)->orderBy('payment_id')->get();
            $data = $rows->map(fn ($row) => $this->record($row))->all();

            return ['data' => $data, 'meta' => ['truncated' => false, 'missing_payment_ids' => array_values(array_diff($paymentIds, array_column($data, 'payment_id')))]];
        });
    }

    public function dailySummaryForPlatforms(array $platformIds, string $from, string $to): array
    {
        return $this->database->connection()->transaction(function () use ($platformIds, $from, $to): array {
            $query = $this->period($this->forPlatforms($platformIds), $from, $to);
            $this->assertResolvedCurrencies($query);
            $rows = $query->select(['platform_id', 'currency'])
                ->selectRaw('DATE(recorded_at) AS recorded_on, COUNT(*) AS candidate_count')
                ->selectRaw('CAST(SUM(amount) AS CHAR) AS candidate_amount')
                ->selectRaw('SUM(CASE WHEN is_reportable_success = 1 THEN 1 ELSE 0 END) AS reportable_count')
                ->selectRaw('CAST(SUM(CASE WHEN is_reportable_success = 1 THEN amount ELSE 0 END) AS CHAR) AS reportable_amount')
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count")
                ->selectRaw("CAST(SUM(CASE WHEN status = 'failed' THEN amount ELSE 0 END) AS CHAR) AS failed_amount")
                ->groupBy('platform_id', 'currency')->groupByRaw('DATE(recorded_at)')
                ->orderBy('platform_id')->orderBy('recorded_on')->orderBy('currency')->get();
            $data = $rows->map(function ($row): array {
                return [
                    'platform_id' => (int) $row->platform_id,
                    'recorded_on' => $row->recorded_on,
                    'currency' => $row->currency,
                    'candidate_count' => (int) $row->candidate_count,
                    'candidate_amount' => $this->decimal($row->candidate_amount),
                    'reportable_count' => (int) $row->reportable_count,
                    'reportable_amount' => $this->decimal($row->reportable_amount),
                    'failed_count' => (int) $row->failed_count,
                    'failed_amount' => $this->decimal($row->failed_amount),
                ];
            })->all();

            return ['data' => $data, 'meta' => ['complete' => true]];
        });
    }

    private function forPlatforms(array $platformIds): Builder
    {
        // All financial reads are against the contracted projection, never a
        // model relationship or a new join that could expose customer fields.
        return $this->database->connection()->table('ewms_kpi_payment_evidence')->whereIn('platform_id', $platformIds);
    }

    private function period(Builder $query, string $from, string $to): Builder
    {
        return $query->where('recorded_at', '>=', $from.' 00:00:00')
            ->where('recorded_at', '<', CarbonImmutable::parse($to, 'UTC')->addDay()->format('Y-m-d').' 00:00:00');
    }

    private function assertResolvedCurrencies(Builder $query): void
    {
        if ((clone $query)->where(fn (Builder $q) => $q->whereNull('currency')->orWhereRaw("TRIM(currency) = ''"))->exists()) {
            throw new RuntimeException('An evidence currency is unresolved.');
        }
    }

    private function records(Builder $query): Builder
    {
        return $query->select(array_values(array_diff(self::COLUMNS, ['amount'])))->selectRaw('CAST(amount AS CHAR) AS amount');
    }

    private function record(object $row): array
    {
        if ($row->recorded_at === null) {
            throw new RuntimeException('An evidence timestamp is unresolved.');
        }

        return [
            'payment_id' => (int) $row->payment_id,
            'crm_user_id' => $row->crm_user_id === null ? null : (int) $row->crm_user_id,
            'platform_id' => (int) $row->platform_id,
            'recorded_at' => CarbonImmutable::parse($row->recorded_at, 'UTC')->toIso8601ZuluString(),
            'amount' => $this->decimal($row->amount),
            'currency' => $row->currency,
            'transaction_reference' => $row->transaction_reference,
            'status' => $row->status,
            'reconciliation_state' => $row->reconciliation_state,
            'resolution_code' => $row->resolution_code,
            'is_reportable_success' => (int) $row->is_reportable_success,
        ];
    }

    private function decimal(string $value): string
    {
        if (! preg_match('/^(-?)([0-9]+)(?:\.([0-9]{1,2}))?$/D', $value, $parts)) {
            throw new RuntimeException('Invalid evidence decimal.');
        }
        $whole = ltrim($parts[2], '0') ?: '0';
        $fraction = str_pad($parts[3] ?? '', 2, '0');
        $sign = $parts[1] === '-' && ($whole !== '0' || $fraction !== '00') ? '-' : '';

        return $sign.$whole.'.'.$fraction;
    }
}
