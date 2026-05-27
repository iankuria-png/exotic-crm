<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\CeoDashboardDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CeoDashboardController extends Controller
{
    public function __construct(
        private readonly CeoDashboardDataService $dashboardData
    ) {
    }

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

    public function recentPayments(Request $request): JsonResponse
    {
        return response()->json($this->dashboardData->recentPayments($request));
    }

    public function agentPerformance(Request $request): JsonResponse
    {
        return response()->json($this->dashboardData->agentPerformance($request));
    }
}
