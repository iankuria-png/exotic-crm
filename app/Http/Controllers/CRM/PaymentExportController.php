<?php

namespace App\Http\Controllers\CRM;

use App\Exports\PaymentExporter;
use App\Http\Controllers\Controller;
use App\Services\MarketAuthorizationService;
use App\Services\PaymentExportDataService;
use App\Services\PaymentQueueQueryBuilder;
use Illuminate\Http\Request;

class PaymentExportController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly PaymentQueueQueryBuilder $paymentQueueQueryBuilder,
        private readonly PaymentExportDataService $paymentExportDataService,
        private readonly PaymentExporter $paymentExporter
    ) {
    }

    public function exportPayments(Request $request)
    {
        $validated = $request->validate($this->validationRules());

        $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this payment market.'
        );

        $columns = array_values(array_intersect(
            $validated['columns'] ?? array_keys(PaymentExporter::columnDefinitions()),
            array_keys(PaymentExporter::columnDefinitions())
        ));

        $rows = $this->paymentExportDataService->build(
            $request,
            $validated,
            $columns,
            ['date_format' => $validated['date_format'] ?? 'Y-m-d']
        );

        $path = $this->paymentExporter->export($rows, $columns, [
            'date_format' => $validated['date_format'] ?? 'Y-m-d',
        ]);

        $fileName = sprintf(
            'crm-payments-%s.xlsx',
            now()->format('Y-m-d-His')
        );

        return response()->streamDownload(function () use ($path) {
            try {
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function validationRules(): array
    {
        return $this->paymentQueueQueryBuilder->validationRules() + [
            'columns' => 'required|array|min:1',
            'columns.*' => 'in:' . implode(',', array_keys(PaymentExporter::columnDefinitions())),
            'date_format' => 'nullable|in:Y-m-d,d/m/Y,m/d/Y',
        ];
    }
}
