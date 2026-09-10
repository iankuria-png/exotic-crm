<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\ForecastScenario;
use App\Services\Forecast\ForecastBaselineService;
use App\Services\Forecast\ForecastContext;
use App\Services\Forecast\ForecastGoalSeekService;
use App\Services\Forecast\ForecastNarrativeService;
use App\Services\Forecast\ForecastScenarioEngine;
use App\Services\MarketAuthorizationService;
use App\Services\ReportingCurrencyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Illuminate\Validation\Rule;

class ForecastController extends Controller
{
    public function __construct(
        private readonly ForecastBaselineService $baselineService,
        private readonly ForecastScenarioEngine $engine,
        private readonly ForecastGoalSeekService $goalSeekService,
        private readonly ForecastNarrativeService $narrativeService,
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly ReportingCurrencyService $reportingCurrencyService
    ) {}

    /**
     * A query string carries booleans as the words "true"/"false", but Laravel's
     * `boolean` rule accepts only real booleans and 1/0/"1"/"0" - so a GET flag
     * that reads perfectly well to $request->boolean() is rejected by the gate in
     * front of it. Coerce only the two recognised words, so genuine rubbish still
     * fails validation rather than being silently read as false.
     */
    private function normalizeBooleanQuery(Request $request, string ...$keys): void
    {
        foreach ($keys as $key) {
            $value = $request->input($key);

            if (is_string($value) && in_array(strtolower($value), ['true', 'false'], true)) {
                $request->merge([$key => strtolower($value) === 'true']);
            }
        }
    }

    public function baseline(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'cache_only');

        $request->validate([
            'cache_only' => 'nullable|boolean',
        ]);

        $context = ForecastContext::fromRequest($request, $this->marketAuthorizationService, $this->reportingCurrencyService);

        if ($context->days > 366) {
            abort(422, 'Forecast windows cannot exceed 366 days.');
        }

        $payload = $this->baselineService->response($context, $request->boolean('cache_only'));
        $status = ($payload['state'] ?? null) === 'building' ? 202 : 200;

        return response()->json($payload, $status);
    }

    public function baselineStatus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'job_token' => 'required|string|max:80',
        ]);

        return response()->json($this->baselineService->status($validated['job_token']));
    }

    public function compute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => 'nullable|in:replay,project,target',
            'levers' => 'nullable|array',
        ]);
        $context = ForecastContext::fromRequest($request, $this->marketAuthorizationService, $this->reportingCurrencyService);
        $baseline = $this->readyBaseline($context);
        $inputs = $this->validatedLeverInputs($validated['levers'] ?? []);

        return response()->json($this->engine->compute($baseline, $inputs, $validated['mode'] ?? $context->mode));
    }

    public function solve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'monthly_target' => 'required|numeric|min:0.01',
            'reach_by_months' => 'required|integer|min:1|max:12',
            'exclude_levers' => 'nullable|array',
            'exclude_levers.*' => ['string', Rule::in($this->engine->leverKeys())],
        ]);
        $context = ForecastContext::fromRequest($request, $this->marketAuthorizationService, $this->reportingCurrencyService);
        $baseline = $this->readyBaseline($context);

        return response()->json($this->goalSeekService->solve(
            $baseline,
            (float) $validated['monthly_target'],
            (int) $validated['reach_by_months'],
            $validated['exclude_levers'] ?? []
        ));
    }

    public function narrate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'kind' => 'required|in:narrative,suggestion,verdict',
            'payload' => 'required|array',
        ]);

        return response()->json($this->narrativeService->generate($validated['kind'], $validated['payload']));
    }

    public function scenarios(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'scored');

        $validated = $request->validate([
            'mode' => 'nullable|in:replay,project,target',
            'scored' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
        ]);

        $query = ForecastScenario::query()
            ->with('creator:id,name', 'platform:id,name,country')
            ->when($validated['mode'] ?? null, fn ($query, string $mode) => $query->where('mode', $mode))
            ->when(array_key_exists('scored', $validated), fn ($query) => $request->boolean('scored')
                ? $query->whereNotNull('scored_at')
                : $query->whereNull('scored_at'))
            ->latest();

        $this->applyScenarioScope($query, $request);

        return response()->json($query->paginate(25));
    }

    public function saveScenario(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('forecast_scenarios', 'name')->where(fn ($query) => $query->where('created_by', $request->user()->id)),
            ],
            'mode' => 'required|in:replay,project,target',
            'horizon_days' => 'nullable|integer|min:1|max:366',
            'target_amount' => 'nullable|numeric|min:0.01',
            'risk_band' => 'nullable|in:conservative,balanced,stretch,downside',
            'levers' => 'nullable|array',
        ]);
        $context = ForecastContext::fromRequest($request, $this->marketAuthorizationService, $this->reportingCurrencyService);
        $baseline = $this->readyBaseline($context);
        $inputs = $this->validatedLeverInputs($validated['levers'] ?? []);
        $snapshot = $this->engine->compute($baseline, $inputs, $validated['mode']);

        try {
            $scenario = ForecastScenario::query()->create([
                'name' => $validated['name'],
                'created_by' => $request->user()->id,
                'platform_id' => $context->platformId,
                'mode' => $validated['mode'],
                'baseline_from' => $context->from->toDateString(),
                'baseline_to' => $context->to->toDateString(),
                'horizon_days' => $context->horizonDays,
                'horizon_ends_on' => $context->to->copy()->addDays($context->horizonDays)->toDateString(),
                'reporting_currency' => $context->currency,
                'target_amount' => $validated['target_amount'] ?? null,
                'risk_band' => $validated['risk_band'] ?? null,
                'levers' => ['schema_version' => 1, 'inputs' => $inputs],
                'snapshot' => $snapshot,
                'config_digest' => (string) ($baseline['config_digest'] ?? $this->baselineService->configDigest()),
            ]);
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'forecast_scenarios_owner_name_unique')) {
                abort(422, 'You already have a forecast scenario with that name.');
            }

            throw $exception;
        }

        return response()->json($scenario->fresh(['creator:id,name', 'platform:id,name,country']), 201);
    }

    public function showScenario(Request $request, ForecastScenario $scenario): JsonResponse
    {
        $this->ensureScenarioVisible($request, $scenario);
        $fresh = $this->freshSnapshotForScenario($request, $scenario);

        return response()->json([
            'scenario' => $scenario->load('creator:id,name', 'platform:id,name,country'),
            'stored_snapshot' => $scenario->snapshot,
            'fresh_snapshot' => $fresh,
            'drift' => $this->drift($scenario->snapshot ?? [], $fresh),
        ]);
    }

    public function renameScenario(Request $request, ForecastScenario $scenario): JsonResponse
    {
        abort_unless((int) $scenario->created_by === (int) $request->user()->id, 403);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('forecast_scenarios', 'name')
                    ->ignore($scenario->id)
                    ->where(fn ($query) => $query->where('created_by', $request->user()->id)),
            ],
        ]);

        $scenario->update(['name' => $validated['name']]);

        return response()->json($scenario->fresh());
    }

    public function deleteScenario(Request $request, ForecastScenario $scenario): JsonResponse
    {
        abort_unless((int) $scenario->created_by === (int) $request->user()->id, 403);
        $scenario->delete();

        return response()->json(['deleted' => true]);
    }

    public function exportScenario(Request $request, ForecastScenario $scenario)
    {
        $this->ensureScenarioVisible($request, $scenario);
        $snapshot = $scenario->snapshot ?? [];

        return Response::streamDownload(function () use ($snapshot) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['section', 'lever', 'market', 'from', 'to', 'contribution', 'eligible_units', 'ceiling_basis']);

            foreach (($snapshot['bridge_rows'] ?? []) as $row) {
                fputcsv($handle, [
                    'bridge',
                    $row['label'] ?? $row['key'] ?? '',
                    '',
                    $row['from'] ?? '',
                    $row['to'] ?? '',
                    $row['contribution'] ?? '',
                    $row['eligible_units'] ?? '',
                    '',
                ]);
            }

            foreach (($snapshot['moves'] ?? []) as $row) {
                fputcsv($handle, [
                    'move',
                    $row['label'] ?? $row['lever'] ?? '',
                    $row['market_label'] ?? '',
                    $row['from'] ?? '',
                    $row['to'] ?? '',
                    $row['contribution'] ?? '',
                    $row['eligible_units'] ?? '',
                    $row['ceiling_basis'] ?? '',
                ]);
            }

            fclose($handle);
        }, 'forecast-scenario-'.$scenario->id.'.csv');
    }

    private function readyBaseline(ForecastContext $context): array
    {
        $payload = $this->baselineService->response($context, false);

        if (($payload['state'] ?? null) !== 'ready') {
            abort(422, 'Forecast baseline is not ready yet.');
        }

        return $payload['baseline'];
    }

    private function validatedLeverInputs(array $inputs): array
    {
        $allowed = $this->engine->leverKeys();
        $clean = [];

        foreach ($inputs as $key => $input) {
            if (! in_array($key, $allowed, true)) {
                abort(422, "Unknown forecast lever: {$key}");
            }

            $target = is_array($input) ? ($input['target'] ?? null) : $input;
            if (! is_numeric($target) || (float) $target < 0) {
                abort(422, "Invalid target for forecast lever: {$key}");
            }

            $clean[$key] = ['target' => (float) $target];
        }

        return $clean;
    }

    private function applyScenarioScope($query, Request $request): void
    {
        $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());

        if (is_array($platformIds)) {
            empty($platformIds)
                ? $query->whereRaw('1 = 0')
                : $query->where(fn ($scope) => $scope->whereNull('platform_id')->orWhereIn('platform_id', $platformIds));
        }
    }

    private function ensureScenarioVisible(Request $request, ForecastScenario $scenario): void
    {
        if (! $this->marketAuthorizationService->userCanAccessPlatform($request->user(), $scenario->platform_id ? (int) $scenario->platform_id : null)) {
            abort(404);
        }
    }

    private function freshSnapshotForScenario(Request $request, ForecastScenario $scenario): array
    {
        $request->merge([
            'from' => $scenario->baseline_from->toDateString(),
            'to' => $scenario->baseline_to->toDateString(),
            'platform_id' => $scenario->platform_id,
            'currency' => $scenario->reporting_currency,
            'mode' => $scenario->mode,
            'horizon_days' => $scenario->horizon_days,
        ]);
        $context = ForecastContext::fromRequest($request, $this->marketAuthorizationService, $this->reportingCurrencyService);
        $baseline = $this->readyBaseline($context);

        return $this->engine->compute($baseline, (array) data_get($scenario->levers, 'inputs', []), $scenario->mode);
    }

    private function drift(array $stored, array $fresh): array
    {
        $storedTotal = (float) ($stored['scenario_total'] ?? 0);
        $freshTotal = (float) ($fresh['scenario_total'] ?? 0);

        return [
            'absolute' => round($freshTotal - $storedTotal, 2),
            'percent' => $storedTotal > 0 ? round((($freshTotal - $storedTotal) / $storedTotal) * 100, 2) : null,
        ];
    }
}
