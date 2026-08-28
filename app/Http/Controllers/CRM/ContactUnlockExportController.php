<?php

namespace App\Http\Controllers\CRM;

use App\Exports\ContactUnlockExporter;
use App\Http\Controllers\Controller;
use App\Models\VisitorContactUnlock;
use App\Services\ContactUnlockQueryService;
use App\Services\MarketAuthorizationService;
use App\Services\ReportingCurrencyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactUnlockExportController extends Controller
{
    public function __construct(
        private readonly ContactUnlockQueryService $unlockQueryService,
        private readonly MarketAuthorizationService $marketAuthorization,
        private readonly ReportingCurrencyService $reportingCurrencyService,
        private readonly ContactUnlockExporter $exporter
    ) {}

    public function export(Request $request)
    {
        $filters = $request->validate([
            'platform_id' => 'nullable|integer|exists:platforms,id',
            'status' => 'nullable|string|max:40',
            'payment_status' => 'nullable|string|max:40',
            'scope' => ['nullable', Rule::in([
                VisitorContactUnlock::SCOPE_SINGLE_PROFILE,
                VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES,
            ])],
            'search' => 'nullable|string|max:120',
            'sort' => ['nullable', Rule::in(['id', 'created_at', 'status', 'scope', 'amount', 'payment_status', 'visitor', 'profile', 'market'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'reporting_currency' => 'nullable|string|min:3|max:8',
        ]);

        $this->marketAuthorization->ensureRequestedPlatformIsAccessible($request);
        $platformIds = $this->marketAuthorization->resolveAccessiblePlatformIds($request->user());
        $query = $this->unlockQueryService->filtered($filters, $platformIds);
        $total = (clone $query)->count();
        $rowsWritten = min($total, ContactUnlockExporter::ROW_LIMIT);
        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($filters['reporting_currency'] ?? null);

        $path = $this->exporter->export(
            $query->reorder()->lazyById(500, 'visitor_contact_unlocks.id', 'id')->take(ContactUnlockExporter::ROW_LIMIT),
            $filters,
            [
                'total' => $total,
                'rows_written' => $rowsWritten,
                'truncated' => $total > ContactUnlockExporter::ROW_LIMIT,
                'normalized_currency' => $targetCurrency,
            ]
        );

        $fileName = sprintf('crm-contact-unlocks-%s.xlsx', now()->format('Y-m-d-His'));

        return response()->streamDownload(function () use ($path) {
            try {
                readfile($path);
            } finally {
                @unlink($path);
            }
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Export-Truncated' => $total > ContactUnlockExporter::ROW_LIMIT ? 'true' : 'false',
            'X-Export-Row-Limit' => (string) ContactUnlockExporter::ROW_LIMIT,
            'X-Export-Row-Total' => (string) $total,
        ]);
    }
}
