<?php

namespace App\Exports;

use App\Models\ContactUnlockEvent;
use App\Models\Payment;
use App\Models\VisitorContactUnlock;
use App\Services\ReportingCurrencyService;
use Illuminate\Support\LazyCollection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ContactUnlockExporter
{
    public const ROW_LIMIT = 5000;

    public function __construct(
        private readonly ReportingCurrencyService $reportingCurrencyService
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function export(LazyCollection $rows, array $filters, array $meta): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Contact Unlocks');

        $headers = [
            'Unlock ID',
            'Created At',
            'Market',
            'Scope',
            'Unlock Status',
            'Profile',
            'WP Post ID',
            'Visitor Phone',
            'Visitor Email',
            'Payment ID',
            'Payment Status',
            'Amount',
            'Currency',
            'Normalized Amount',
            'Normalized Currency',
            'Reference',
            'Provider',
            'Provider Environment',
            'Traffic Source',
            'Failure Reason',
        ];
        $sheet->fromArray([$headers], null, 'A1');

        $targetCurrency = (string) ($meta['normalized_currency'] ?? $this->reportingCurrencyService->resolveTargetCurrency($filters['reporting_currency'] ?? null));
        $rowIndex = 2;
        foreach ($rows as $unlock) {
            $payment = $unlock->payment;
            $normalized = $payment
                ? $this->reportingCurrencyService->normalizePaymentQuery(Payment::query()->whereKey((int) $payment->id), $targetCurrency, false)
                : ['normalized_total' => null, 'normalized_currency' => $targetCurrency];

            $sheet->fromArray([[
                $unlock->id,
                $unlock->created_at?->toDateTimeString(),
                $unlock->platform?->name,
                $unlock->scope,
                $unlock->status,
                $unlock->client?->name,
                $unlock->wp_post_id ?: $unlock->client?->wp_post_id,
                $unlock->visitor_phone_masked,
                $unlock->visitor_email_masked,
                $payment?->id,
                $payment?->status,
                $payment?->amount,
                $payment?->currency,
                $normalized['normalized_total'],
                $normalized['normalized_currency'] ?? $targetCurrency,
                $payment?->reference_number ?: $payment?->transaction_reference,
                $payment?->provider_key,
                $payment?->provider_environment,
                $this->trafficSource($unlock),
                $payment?->failure_reason,
            ]], null, 'A'.$rowIndex);
            $rowIndex++;
        }

        $this->styleHeader($sheet);
        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $metaSheet = $spreadsheet->createSheet();
        $metaSheet->setTitle('Export Meta');
        $metaSheet->fromArray([
            ['Generated At', now()->toIso8601String()],
            ['Row Limit', self::ROW_LIMIT],
            ['Rows Written', $meta['rows_written'] ?? 0],
            ['Total Matched', $meta['total'] ?? 0],
            ['Truncated', ! empty($meta['truncated']) ? 'true' : 'false'],
            ['Filters', json_encode($filters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
        ], null, 'A1');
        foreach (range('A', $metaSheet->getHighestColumn()) as $column) {
            $metaSheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $path = $this->temporaryPath('contact-unlocks');
        $writer->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($writer, $spreadsheet);

        return $path;
    }

    private function trafficSource(VisitorContactUnlock $unlock): string
    {
        $sessionHash = trim((string) $unlock->session_token_hash);
        if ($sessionHash === '') {
            return '';
        }

        return (string) (ContactUnlockEvent::query()
            ->where('session_hash', $sessionHash)
            ->where('event_type', ContactUnlockEvent::TYPE_CHECKOUT_START)
            ->orderBy('occurred_at')
            ->value('traffic_source') ?: '');
    }

    private function styleHeader($sheet): void
    {
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFE2E8F0');
    }

    private function temporaryPath(string $prefix): string
    {
        $temp = tempnam(sys_get_temp_dir(), $prefix.'-');
        $path = $temp.'.xlsx';
        @unlink($temp);

        return $path;
    }
}
