<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ScorecardExporter
{
    private const TITLES = [
        'revenue' => 'Revenue',
        'client_snapshot' => 'Active Clients',
        'daily_peak' => 'Daily Peak',
        'best_package' => 'Best Package',
        'conversion' => 'Conversion',
        'contact_mix' => 'Contact Mix',
    ];

    public function export(array $data, array $config): string
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->removeSheetByIndex(0);

        $sections = array_values($config['sections'] ?? array_keys($data));
        foreach ($sections as $index => $section) {
            if (!array_key_exists($section, $data)) {
                continue;
            }

            $sheet = $index === 0
                ? $spreadsheet->createSheet(0)
                : $spreadsheet->createSheet();
            $sheet->setTitle(self::TITLES[$section] ?? ucfirst(str_replace('_', ' ', $section)));

            match ($section) {
                'revenue' => $this->writeRevenueSheet($sheet, $data[$section]),
                'client_snapshot' => $this->writeClientSnapshotSheet($sheet, $data[$section]),
                'daily_peak' => $this->writeDailyPeakSheet($sheet, $data[$section]),
                'best_package' => $this->writeBestPackageSheet($sheet, $data[$section]),
                'conversion' => $this->writeConversionSheet($sheet, $data[$section]),
                'contact_mix' => $this->writeContactMixSheet($sheet, $data[$section]),
                default => null,
            };
        }

        $spreadsheet->setActiveSheetIndex(0);
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $path = $this->temporaryPath('scorecard');
        $writer->save($path);

        $spreadsheet->disconnectWorksheets();
        unset($writer, $spreadsheet);

        return $path;
    }

    private function writeRevenueSheet($sheet, array $section): void
    {
        $sheet->fromArray(
            [['Lifecycle', 'Amount', 'Currency', 'Normalized Amount', 'Normalized Currency']],
            null,
            'A1'
        );

        $row = 2;
        foreach ($section['buckets'] ?? [] as $bucket) {
            foreach (($bucket['breakdown'] ?? []) as $currency => $amount) {
                $sheet->fromArray([[
                    $bucket['label'] ?? ucfirst((string) ($bucket['key'] ?? '')),
                    $amount,
                    $currency,
                    $bucket['normalized_total'],
                    $bucket['normalized_currency'],
                ]], null, 'A' . $row);
                $row++;
            }
        }

        $sheet->fromArray([[
            'Total',
            $section['total']['scalar_amount'] ?? null,
            count($section['total']['breakdown'] ?? []) === 1 ? array_key_first($section['total']['breakdown']) : null,
            $section['total']['normalized_total'] ?? null,
            $section['total']['normalized_currency'] ?? null,
        ]], null, 'A' . $row);

        $this->styleSheet($sheet, 'A1:E1', 'A:E');
    }

    private function writeClientSnapshotSheet($sheet, array $section): void
    {
        $sheet->fromArray(
            [['Metric', 'Date', 'Count']],
            null,
            'A1'
        );

        $sheet->fromArray([
            ['Start Active Clients', $section['start_date'] ?? null, $section['start_count'] ?? null],
            ['End Active Clients', $section['end_date'] ?? null, $section['end_count'] ?? null],
            ['Net Change', null, $section['change'] ?? null],
        ], null, 'A2');

        $this->styleSheet($sheet, 'A1:C1', 'A:C');
    }

    private function writeDailyPeakSheet($sheet, array $section): void
    {
        $sheet->fromArray(
            [['Date', 'Marker', 'Amount', 'Currency', 'Normalized Amount', 'Normalized Currency']],
            null,
            'A1'
        );

        $topDate = $section['top_day']['date'] ?? null;
        $lowDate = $section['low_day']['date'] ?? null;
        $row = 2;

        foreach (($section['rows'] ?? []) as $day) {
            $marker = $day['date'] === $topDate
                ? 'Highest'
                : ($day['date'] === $lowDate ? 'Lowest' : null);

            $wroteBreakdown = false;
            foreach (($day['breakdown'] ?? []) as $currency => $amount) {
                $sheet->fromArray([[
                    $day['date'],
                    !$wroteBreakdown ? $marker : null,
                    $amount,
                    $currency,
                    !$wroteBreakdown ? ($day['normalized_total'] ?? null) : null,
                    !$wroteBreakdown ? ($day['normalized_currency'] ?? null) : null,
                ]], null, 'A' . $row);
                $wroteBreakdown = true;
                $row++;
            }
        }

        $this->styleSheet($sheet, 'A1:F1', 'A:F');
    }

    private function writeBestPackageSheet($sheet, array $section): void
    {
        $sheet->fromArray(
            [['Package', 'Amount', 'Currency', 'Normalized Amount', 'Normalized Currency']],
            null,
            'A1'
        );

        $row = 2;
        foreach (($section['rows'] ?? []) as $package) {
            $wroteBreakdown = false;
            foreach (($package['revenue_breakdown'] ?? []) as $currency => $amount) {
                $sheet->fromArray([[
                    !$wroteBreakdown ? ($package['label'] ?? null) : null,
                    $amount,
                    $currency,
                    !$wroteBreakdown ? ($package['normalized_total'] ?? null) : null,
                    !$wroteBreakdown ? ($package['normalized_currency'] ?? null) : null,
                ]], null, 'A' . $row);
                $wroteBreakdown = true;
                $row++;
            }
        }

        $this->styleSheet($sheet, 'A1:E1', 'A:E');
    }

    private function writeConversionSheet($sheet, array $section): void
    {
        $sheet->fromArray(
            [['Stage', 'Count', 'Share Of Total', 'Conversion From Previous', 'Dropoff From Previous']],
            null,
            'A1'
        );

        $row = 2;
        foreach (($section['stages'] ?? []) as $stage) {
            $sheet->fromArray([[
                $stage['label'] ?? null,
                $stage['count'] ?? null,
                $stage['share_of_total'] ?? null,
                $stage['conversion_from_previous'] ?? null,
                $stage['dropoff_from_previous'] ?? null,
            ]], null, 'A' . $row);
            $row++;
        }

        $row++;
        $sheet->fromArray(
            [['Conversion Rate', $section['conversion_rate'] ?? null, 'Total Leads', $section['totals']['total'] ?? null]],
            null,
            'A' . $row
        );

        $this->styleSheet($sheet, 'A1:E1', 'A:E');
    }

    private function writeContactMixSheet($sheet, array $section): void
    {
        $sheet->fromArray(
            [['Platform', 'Contact Method', 'Total', 'Percent', 'Error']],
            null,
            'A1'
        );

        $row = 2;
        foreach (($section['platforms'] ?? []) as $platform) {
            if (!empty($platform['error'])) {
                $sheet->fromArray([[
                    $platform['platform_name'] ?? null,
                    null,
                    null,
                    null,
                    $platform['error'],
                ]], null, 'A' . $row);
                $row++;
                continue;
            }

            foreach (($platform['platform_contact_mix'] ?? []) as $method => $mixRow) {
                $sheet->fromArray([[
                    $platform['platform_name'] ?? null,
                    $method,
                    $mixRow['total'] ?? null,
                    $mixRow['percent'] ?? null,
                    null,
                ]], null, 'A' . $row);
                $row++;
            }
        }

        $this->styleSheet($sheet, 'A1:E1', 'A:E');
    }

    private function styleSheet($sheet, string $headerRange, string $columnRange): void
    {
        $sheet->getStyle($headerRange)->getFont()->setBold(true);
        $sheet->getStyle($headerRange)->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFE2E8F0');

        [$startColumn, $endColumn] = explode(':', $columnRange);
        foreach (range($startColumn, $endColumn) as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }
    }

    private function temporaryPath(string $prefix): string
    {
        $temp = tempnam(sys_get_temp_dir(), $prefix . '-');
        $path = $temp . '.xlsx';
        @unlink($temp);

        return $path;
    }
}
