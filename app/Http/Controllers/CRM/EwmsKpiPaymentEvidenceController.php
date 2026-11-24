<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Ewms\EwmsKpiAccess;
use App\Services\Ewms\EwmsKpiPaymentEvidenceQuery;
use App\Services\Ewms\EwmsKpiReadAudit;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class EwmsKpiPaymentEvidenceController extends Controller
{
    public function __construct(private readonly EwmsKpiPaymentEvidenceQuery $query, private readonly EwmsKpiReadAudit $audit) {}

    public function records(Request $request): JsonResponse
    {
        return $this->respond($request, false);
    }

    public function summary(Request $request): JsonResponse
    {
        return $this->respond($request, true);
    }

    private function respond(Request $request, bool $summary): JsonResponse
    {
        try {
            $input = $this->validated($request, $summary);
            $result = $summary
                ? $this->query->dailySummaryForPlatforms($input['platform_ids'], $input['from'], $input['to'])
                : (isset($input['payment_ids'])
                    ? $this->query->byPaymentIdsForPlatforms($input['platform_ids'], $input['payment_ids'])
                    : $this->query->recentForPlatforms($input['platform_ids'], $input['from'], $input['to'], $input['limit']));
            $result['meta'] = array_merge($result['meta'], [
                'contract' => EwmsKpiAccess::CONTRACT,
                'population' => 'crm_cs_revenue',
                'timezone' => 'UTC',
                'date_basis' => 'completed_at_or_created_at',
                'generated_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            ]);
            $this->audit->record($request, $summary ? 'ewms_kpi_payment_evidence_summary_read' : 'ewms_kpi_payment_evidence_read', $input, $result);

            return response()->json($result)->header('Cache-Control', 'no-store');
        } catch (ValidationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // SQL bindings, exception text, references and token values must not
            // leak into diagnostics or a partial-success response.
            Log::warning('EWMS evidence read unavailable.', [
                'exception_class' => get_class($exception),
                'request_id' => $request->attributes->get('request_id'),
            ]);

            return response()->json(['message' => 'Payment evidence is temporarily unavailable.', 'code' => 'ewms_evidence_unavailable'], 503)
                ->header('Cache-Control', 'no-store');
        }
    }

    private function validated(Request $request, bool $summary): array
    {
        $keys = $summary ? ['platform_ids', 'from', 'to'] : ['platform_ids', 'from', 'to', 'limit', 'payment_ids'];
        if (array_diff(array_keys($request->all()), $keys)) {
            throw ValidationException::withMessages(['request' => 'Unknown request fields.']);
        }
        $refresh = ! $summary && $request->exists('payment_ids');
        // Presence, including explicit nulls, cannot mix the two query modes.
        if ($refresh && ($request->exists('from') || $request->exists('to') || $request->exists('limit'))) {
            throw ValidationException::withMessages(['payment_ids' => 'Payment-ID refresh cannot include dates or a limit.']);
        }
        $rules = [
            'platform_ids' => ['required', 'array', 'min:1', 'max:100'],
            'platform_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
        $rules += $refresh ? [
            'payment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'payment_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ] : [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
        if (! $summary && ! $refresh) {
            $rules['limit'] = ['sometimes', 'integer', 'min:1', 'max:500'];
        }
        $input = Validator::make($request->all(), $rules)->validate();
        foreach (['platform_ids', 'payment_ids'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = array_values(array_map('intval', $input[$key]));
            }
        }
        if (! $refresh && CarbonImmutable::parse($input['from'], 'UTC')->diffInDays(CarbonImmutable::parse($input['to'], 'UTC')) >= 93) {
            throw ValidationException::withMessages(['to' => 'The inclusive reporting period may not exceed 93 days.']);
        }
        $allowed = EwmsKpiAccess::allowedPlatformIds();
        abort_if(array_diff($input['platform_ids'], $allowed) || DB::table('platforms')->whereIn('id', $input['platform_ids'])->count() !== count($input['platform_ids']), 403, 'Requested platforms are outside this integration.');
        if (! $summary && ! $refresh) {
            $input['limit'] = (int) ($input['limit'] ?? 50);
        }

        return $input;
    }
}
