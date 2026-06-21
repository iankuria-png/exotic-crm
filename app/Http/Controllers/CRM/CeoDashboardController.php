<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Services\CeoDashboardDataService;
use App\Services\MarketHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CeoDashboardController extends Controller
{
    public function __construct(
        private readonly CeoDashboardDataService $dashboardData,
        private readonly MarketHealthService $marketHealthService
    ) {}

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->dashboardData->summary($request));
    }

    public function marketPie(Request $request): JsonResponse
    {
        return response()->json($this->dashboardData->marketPie($request));
    }

    public function revenueTrend(Request $request): JsonResponse
    {
        return response()->json($this->dashboardData->revenueTrend($request));
    }

    public function peakHours(Request $request): JsonResponse
    {
        return response()->json($this->dashboardData->peakHours($request));
    }

    public function recentPayments(Request $request): JsonResponse
    {
        return response()->json($this->dashboardData->recentPayments($request));
    }

    public function agentPerformance(Request $request): JsonResponse
    {
        return response()->json($this->dashboardData->agentPerformance($request));
    }

    public function marketHealth(Request $request): JsonResponse
    {
        return response()->json($this->dashboardData->marketHealth($request));
    }

    public function checkMarketHealth(Request $request, Platform $platform): JsonResponse
    {
        $result = $this->marketHealthService->checkAndStore($platform);

        return response()->json([
            'market' => $this->dashboardData->marketHealthRow($result['platform']),
            'transitioned_down' => (bool) ($result['transitioned_down'] ?? false),
        ]);
    }
}
