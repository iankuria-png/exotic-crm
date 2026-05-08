<?php

namespace App\Http\Controllers\CRM;

use App\Exports\ScorecardExporter;
use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Services\MarketAuthorizationService;
use App\Services\ReportingCurrencyService;
use App\Services\ScorecardDataService;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ScorecardExportController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly ReportingCurrencyService $reportingCurrencyService,
        private readonly ScorecardDataService $scorecardDataService,
        private readonly ScorecardExporter $scorecardExporter
    ) {
    }

    public function weeklyScorecard(Request $request)
    {
        [$validated, $from, $to, $selectedPlatformId, $platformIds, $targetCurrency] = $this->resolveRequestState($request);
        $sections = $this->requestedSections($validated['sections'] ?? []);

        return response()->json([
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'filters' => [
                'platform_id' => $selectedPlatformId,
                'reporting_currency' => $targetCurrency,
            ],
            'sections' => $this->scorecardDataService->assemble(
                $from,
                $to,
                $platformIds,
                $targetCurrency,
                $sections
            ),
        ]);
    }

    public function exportScorecard(Request $request)
    {
        [$validated, $from, $to, $selectedPlatformId, $platformIds, $targetCurrency] = $this->resolveRequestState($request);
        $sections = $this->requestedSections($validated['sections'] ?? []);

        $data = $this->scorecardDataService->assemble(
            $from,
            $to,
            $platformIds,
            $targetCurrency,
            $sections
        );

        $path = $this->scorecardExporter->export($data, [
            'sections' => $sections,
            'date_format' => $validated['date_format'] ?? 'Y-m-d',
        ]);

        $fileName = sprintf(
            'crm-scorecard-%s-to-%s.xlsx',
            $from->toDateString(),
            $to->toDateString()
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

    private function resolveRequestState(Request $request): array
    {
        $validated = $request->validate([
            'platform_id' => 'nullable|exists:platforms,id',
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
            'currency_mode' => 'nullable|in:native,flat',
            'reporting_currency' => 'nullable|string|min:3|max:8',
            'sections' => 'nullable|array',
            'sections.*' => 'in:revenue,client_snapshot,daily_peak,best_package,conversion,contact_mix',
            'date_format' => 'nullable|in:Y-m-d,d/m/Y,m/d/Y',
        ]);

        $from = !empty($validated['from'])
            ? Carbon::parse($validated['from'])->startOfDay()
            : now()->subMonths(5)->startOfMonth();
        $to = !empty($validated['to'])
            ? Carbon::parse($validated['to'])->endOfDay()
            : now()->endOfDay();

        $baselineCutoff = $this->resolveBaselineCutoff();
        if ($baselineCutoff && $baselineCutoff->gt($from)) {
            $from = $baselineCutoff;
        }

        $selectedPlatformId = $this->marketAuthorizationService->ensureRequestedPlatformIsAccessible(
            $request,
            'platform_id',
            'You do not have access to this report market.'
        );

        $platformIds = $selectedPlatformId
            ? [(int) $selectedPlatformId]
            : $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        if ($platformIds === null) {
            $platformIds = Platform::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $targetCurrency = $this->reportingCurrencyService->resolveTargetCurrency($validated['reporting_currency'] ?? null);

        return [$validated, $from, $to, $selectedPlatformId, $platformIds, $targetCurrency];
    }

    /**
     * @param  array<int, string>  $sections
     * @return array<int, string>
     */
    private function requestedSections(array $sections): array
    {
        if ($sections === []) {
            return [
                'revenue',
                'client_snapshot',
                'daily_peak',
                'best_package',
                'conversion',
            ];
        }

        return array_values(array_intersect($sections, ScorecardDataService::AVAILABLE_SECTIONS));
    }

    private function resolveBaselineCutoff(): ?Carbon
    {
        try {
            $value = \App\Models\IntegrationSetting::query()
                ->where('key', 'data_baseline_mode')
                ->value('value');
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($value)) {
            return null;
        }

        $mode = $value['mode'] ?? 'fresh_start';
        $cutoffDate = $value['cutoff_date'] ?? null;
        if ($mode !== 'fresh_start' || !$cutoffDate) {
            return null;
        }

        try {
            return Carbon::parse($cutoffDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
