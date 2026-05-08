<?php

namespace Tests\Unit\Exports;

use App\Exports\ScorecardExporter;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class ScorecardExporterTest extends TestCase
{
    public function test_scorecard_exporter_writes_expected_sheets_and_headers(): void
    {
        $exporter = new ScorecardExporter();

        $path = $exporter->export([
            'revenue' => [
                'buckets' => [
                    'new' => [
                        'key' => 'new',
                        'label' => 'New',
                        'breakdown' => ['KES' => 1200],
                        'normalized_total' => 9.3,
                        'normalized_currency' => 'USD',
                    ],
                ],
                'total' => [
                    'breakdown' => ['KES' => 1200],
                    'scalar_amount' => 1200,
                    'normalized_total' => 9.3,
                    'normalized_currency' => 'USD',
                ],
            ],
            'client_snapshot' => [
                'start_date' => '2026-05-01',
                'start_count' => 10,
                'end_date' => '2026-05-07',
                'end_count' => 14,
                'change' => 4,
            ],
        ], [
            'sections' => ['revenue', 'client_snapshot'],
        ]);

        $spreadsheet = IOFactory::load($path);

        $this->assertSame('Revenue', $spreadsheet->getSheet(0)->getTitle());
        $this->assertSame('Lifecycle', $spreadsheet->getSheet(0)->getCell('A1')->getValue());
        $this->assertSame('Active Clients', $spreadsheet->getSheet(1)->getTitle());
        $this->assertSame('Metric', $spreadsheet->getSheet(1)->getCell('A1')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }
}
