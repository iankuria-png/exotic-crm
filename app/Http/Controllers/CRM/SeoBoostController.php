<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\SeoBoostBatch;
use App\Services\MarketAuthorizationService;
use App\Services\SeoBoostService;
use App\Services\WalletSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SeoBoostController extends Controller
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly SeoBoostService $seoBoostService,
        private readonly WalletSettingsService $walletSettingsService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $platformId = $this->authorizedPlatformId($request, (int) $validated['platform_id']);
        $limit = (int) ($validated['limit'] ?? 10);

        $batches = SeoBoostBatch::query()
            ->with(['creator:id,name,email', 'product:id,name,display_name,tier'])
            ->where('platform_id', $platformId)
            ->latest('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'batches' => $batches,
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'targets' => 'required|array|min:1|max:20',
            'targets.*.canonical_key' => 'required|string|max:160',
            'targets.*.display_city' => 'required|string|max:160',
            'targets.*.target_count' => 'required|integer|min:1|max:100',
            'limit' => 'nullable|integer|min:1|max:200',
            'selected_client_ids' => 'nullable|array|max:200',
            'selected_client_ids.*' => 'integer|exists:clients,id',
        ]);

        $platformId = $this->authorizedPlatformId($request, (int) $validated['platform_id']);

        return response()->json($this->seoBoostService->preview(
            $platformId,
            $validated['targets'],
            (int) ($validated['limit'] ?? 80),
            $validated['selected_client_ids'] ?? []
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'product_id' => 'required|integer|exists:products,id',
            'product_price_id' => 'nullable|integer|exists:product_prices,id',
            'duration_days' => 'required|integer|min:1|max:90',
            'targets' => 'required|array|min:1|max:20',
            'targets.*.canonical_key' => 'required|string|max:160',
            'targets.*.display_city' => 'required|string|max:160',
            'targets.*.target_count' => 'required|integer|min:1|max:100',
            'selected_client_ids' => 'required|array|min:1|max:200',
            'selected_client_ids.*' => 'integer|exists:clients,id',
            'free_trial_pin' => ['required', 'regex:/^\d{4,6}$/'],
            'notes' => 'nullable|string|max:1000',
        ]);

        $platformId = $this->authorizedPlatformId($request, (int) $validated['platform_id']);

        if (!$this->walletSettingsService->freeTrialPinIsConfigured()) {
            return response()->json([
                'message' => 'Free-trial PIN is not configured. Ask an admin to set it in Settings first.',
            ], 409);
        }

        if (!$this->walletSettingsService->verifyFreeTrialPin((string) $validated['free_trial_pin'])) {
            throw ValidationException::withMessages([
                'free_trial_pin' => 'Free-trial PIN is invalid.',
            ]);
        }

        $result = $this->seoBoostService->createBatch(
            $platformId,
            (int) $request->user()->id,
            (int) $validated['product_id'],
            isset($validated['product_price_id']) ? (int) $validated['product_price_id'] : null,
            (int) $validated['duration_days'],
            $validated['targets'],
            $validated['selected_client_ids'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'message' => 'SEO Boost batch created.',
            ...$result,
        ], 201);
    }

    public function show(Request $request, SeoBoostBatch $batch): JsonResponse
    {
        $this->authorizedPlatformId($request, (int) $batch->platform_id);

        return response()->json($this->seoBoostService->show((int) $batch->id));
    }

    private function authorizedPlatformId(Request $request, int $platformId): int
    {
        $this->marketAuthorizationService->ensureRole(
            $request->user(),
            [
                MarketAuthorizationService::ROLE_ADMIN,
                MarketAuthorizationService::ROLE_SUB_ADMIN,
                MarketAuthorizationService::ROLE_SALES,
                MarketAuthorizationService::ROLE_FIELD_SALES,
            ],
            'You do not have permission to run SEO Boost batches.'
        );

        $this->marketAuthorizationService->ensureUserCanAccessPlatform(
            $request->user(),
            $platformId,
            'You do not have access to this SEO Boost market.'
        );

        return $platformId;
    }
}
