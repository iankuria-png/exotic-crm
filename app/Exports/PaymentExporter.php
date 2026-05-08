<?php

namespace App\Exports;

use App\Models\Payment;
use Illuminate\Support\LazyCollection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class PaymentExporter
{
    /**
     * @return array<string, string>
     */
    public static function columnDefinitions(): array
    {
        return [
            'id' => 'ID',
            'phone' => 'Phone',
            'amount' => 'Amount',
            'currency' => 'Currency',
            'status' => 'Status',
            'completed_at' => 'Completed At',
            'created_at' => 'Created At',
            'transaction_reference' => 'Transaction Reference',
            'client_name' => 'Client Name',
            'deal_subscription_lifecycle' => 'Subscription Lifecycle',
            'product_name' => 'Product Name',
            'match_confidence' => 'Match Confidence',
        ];
    }

    public function export(LazyCollection $rows, array $columns, array $formatOptions): string
    {
        $definitions = self::columnDefinitions();
        $selectedColumns = array_values(array_filter($columns, fn ($column) => array_key_exists($column, $definitions)));
        $dateFormat = (string) ($formatOptions['date_format'] ?? 'Y-m-d');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Payments');
        $sheet->fromArray([array_map(fn ($column) => $definitions[$column], $selectedColumns)], null, 'A1');

        $rowIndex = 2;
        foreach ($rows as $payment) {
            $sheet->fromArray([$this->mapRow($payment, $selectedColumns, $dateFormat)], null, 'A' . $rowIndex);
            $rowIndex++;
        }

        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
        $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFE2E8F0');

        foreach (range('A', $sheet->getHighestColumn()) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $path = $this->temporaryPath('payments');
        $writer->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($writer, $spreadsheet);

        return $path;
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, mixed>
     */
    private function mapRow(Payment $payment, array $columns, string $dateFormat): array
    {
        return array_map(function (string $column) use ($payment, $dateFormat) {
            return match ($column) {
                'id' => $payment->id,
                'phone' => $payment->phone,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'completed_at' => $this->formatDateValue($payment->completed_at, $dateFormat),
                'created_at' => $this->formatDateValue($payment->created_at, $dateFormat),
                'transaction_reference' => $payment->transaction_reference,
                'client_name' => $payment->client?->name,
                'deal_subscription_lifecycle' => $payment->subscription_lifecycle ?: $payment->deal?->subscription_lifecycle,
                'product_name' => $payment->product?->name,
                'match_confidence' => $payment->match_confidence,
                default => null,
            };
        }, $columns);
    }

    private function formatDateValue($value, string $dateFormat): ?string
    {
        if (!$value) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? $value->format($dateFormat)
            : date($dateFormat, strtotime((string) $value));
    }

    private function temporaryPath(string $prefix): string
    {
        $temp = tempnam(sys_get_temp_dir(), $prefix . '-');
        $path = $temp . '.xlsx';
        @unlink($temp);

        return $path;
    }
}
