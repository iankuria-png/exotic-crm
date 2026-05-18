<?php

namespace App\Http\Controllers\CRM\Kyc;

use App\Http\Controllers\Controller;
use App\Models\KycSubject;
use App\Services\Kyc\KycSettingsService;
use App\Services\MarketAuthorizationService;
use Illuminate\Http\Request;

class QueueController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly KycSettingsService $settingsService,
    ) {
    }

    public function index(Request $request)
    {
        $query = KycSubject::query()->with(['client.platform', 'client.activeDeal.product', 'sites'])
            ->whereHas('client', fn ($builder) => $builder->where('kyc_required', true));

        $platformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($request->user());
        if (($request->user()->role ?? '') === 'sales') {
            $query->whereHas('client', fn ($builder) => $builder->whereIn('platform_id', $platformIds ?: [0]));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('platform_id')) {
            $platformId = (int) $request->input('platform_id');
            $this->marketAuthorizationService->ensureUserCanAccessPlatform($request->user(), $platformId);
            $query->whereHas('client', fn ($builder) => $builder->where('platform_id', $platformId));
        }

        $sort = (string) $request->input('sort', 'oldest_in_review');
        if ($sort === 'overdue') {
            $query->orderBy('grace_started_at')->orderBy('updated_at');
        } else {
            $query->orderByRaw("CASE WHEN status = 'in_review' THEN 0 WHEN status = 'info_requested' THEN 1 ELSE 2 END")
                ->orderBy('updated_at');
        }

        return response()->json($query->paginate((int) $request->input('per_page', 25)));
    }

    public function count(Request $request)
    {
        return response()->json($this->settingsService->queueCountForUser($request->user()));
    }
}
