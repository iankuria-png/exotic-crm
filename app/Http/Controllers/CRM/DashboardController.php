<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ClientNote;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $expiringDeals = Deal::expiringSoon(7)
            ->with(['client', 'product'])
            ->orderBy('expires_at')
            ->limit(10)
            ->get();

        $recentPayments = Payment::where('status', 'completed')
            ->with(['platform', 'product'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        $upcomingFollowUps = ClientNote::withPendingFollowUp()
            ->with(['client', 'author'])
            ->orderBy('follow_up_at')
            ->limit(10)
            ->get();

        return response()->json([
            'kpis' => [
                'active_clients' => Client::active()->count(),
                'total_clients' => Client::count(),
                'pending_leads' => Lead::new()->count(),
                'total_leads' => Lead::count(),
                'active_deals' => Deal::active()->count(),
                'expiring_soon' => Deal::expiringSoon(7)->count(),
                'recent_payments' => Payment::where('status', 'completed')
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count(),
                'revenue_mtd' => (float) Payment::where('status', 'completed')
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->sum('amount'),
                'unmatched_payments' => Payment::whereNull('client_id')
                    ->where('status', 'completed')
                    ->count(),
            ],
            'expiring_deals' => $expiringDeals,
            'recent_payments' => $recentPayments,
            'upcoming_follow_ups' => $upcomingFollowUps,
        ]);
    }

    public function products()
    {
        return response()->json(Product::where('is_active', true)->get());
    }
}
