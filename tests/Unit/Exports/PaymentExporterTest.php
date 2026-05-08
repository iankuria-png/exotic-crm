<?php

namespace Tests\Unit\Exports;

use App\Exports\PaymentExporter;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\LazyCollection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class PaymentExporterTest extends TestCase
{
    public function test_payment_exporter_writes_selected_columns_in_order(): void
    {
        $payment = new Payment();
        $payment->forceFill([
            'phone' => '254700000001',
            'status' => 'completed',
        ]);
        $payment->id = 42;
        $payment->created_at = Carbon::parse('2026-05-02 10:00:00');
        $payment->setRelation('client', new Client(['name' => 'Client One']));
        $payment->setRelation('product', new Product(['name' => 'VIP']));

        $exporter = new PaymentExporter();
        $path = $exporter->export(
            LazyCollection::make([$payment]),
            ['id', 'client_name', 'product_name', 'created_at'],
            ['date_format' => 'Y-m-d']
        );

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('ID', $sheet->getCell('A1')->getValue());
        $this->assertSame('Client Name', $sheet->getCell('B1')->getValue());
        $this->assertSame('Product Name', $sheet->getCell('C1')->getValue());
        $this->assertSame('Created At', $sheet->getCell('D1')->getValue());
        $this->assertSame('Client One', $sheet->getCell('B2')->getValue());
        $this->assertSame('VIP', $sheet->getCell('C2')->getValue());
        $this->assertSame('2026-05-02', $sheet->getCell('D2')->getValue());

        $spreadsheet->disconnectWorksheets();
        @unlink($path);
    }
}
