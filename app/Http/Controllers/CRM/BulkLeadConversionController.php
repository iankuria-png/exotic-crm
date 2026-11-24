<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Services\BulkLeadConversionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class BulkLeadConversionController extends Controller
{
    public function preview(Request $request, BulkLeadConversionService $service)
    {
        $data = $request->validate([
            'lead_ids' => 'required|array|min:1|max:'.BulkLeadConversionService::LIMIT,
            'lead_ids.*' => 'required|integer|distinct|min:1',
            // Optional WordPress location per lead (Lead Scraper "convert & place").
            'placements' => 'nullable|array|max:'.BulkLeadConversionService::LIMIT,
            'placements.*.region_id' => 'required_with:placements|integer|min:1',
            'placements.*.city_id' => 'nullable|integer|min:1',
            // Lead Scraper: starter profile facts, an AI bio and the listing photo after creation.
            'smart_defaults' => 'nullable|boolean',
            'ai_bio' => 'nullable|boolean',
            'photo' => 'nullable|boolean',
        ]);

        return response()->json($service->preview($request, array_map('intval', $data['lead_ids']), (array) ($data['placements'] ?? []), [
            'smart_defaults' => (bool) ($data['smart_defaults'] ?? false),
            'ai_bio' => (bool) ($data['ai_bio'] ?? false),
            'photo' => (bool) ($data['photo'] ?? false),
        ]));
    }

    public function store(Request $request, Lead $lead, BulkLeadConversionService $service)
    {
        $data = $request->validate(['review_token' => 'required|string|max:8192', 'reason' => 'nullable|string|max:500']);
        try {
            return response()->json($service->execute($request, $lead, $data['review_token'], $data['reason'] ?? null));
        } catch (\Illuminate\Validation\ValidationException $exception) {
            throw $exception;
        } catch (HttpExceptionInterface $exception) {
            $recovery = str_contains($exception->getMessage(), 'different payload');

            return response()->json([
                'status' => $recovery ? 'recovery_required' : (str_starts_with($exception->getMessage(), 'Another conversion') ? 'busy' : 'review_required'),
                'message' => $recovery ? 'A previous attempt created a WordPress profile with different details. Review this lead individually before retrying.' : $exception->getMessage(),
            ], $exception->getStatusCode());
        } catch (\Throwable $exception) {
            Log::error('Bulk lead conversion failed', ['lead_id' => $lead->id, 'exception' => get_class($exception), 'error' => $exception->getMessage()]);

            return response()->json(['status' => 'failed', 'message' => 'Conversion could not finish. Retry this lead to safely check or complete it.'], 500);
        }
    }
}
