<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationOverviewController extends Controller
{
    public function __invoke(Request $request, DashboardController $dashboard): JsonResponse
    {
        // Conversations already consumed this scoped calculation. Expose only
        // its existing follow-ups and counter, without the dashboard payload.
        $summary = $dashboard->summary($request)->getData(true);

        return response()->json([
            'upcoming_follow_ups' => $summary['upcoming_follow_ups'] ?? [],
            'kpis' => ['unmatched_payments' => (int) data_get($summary, 'kpis.unmatched_payments', 0)],
        ]);
    }
}
