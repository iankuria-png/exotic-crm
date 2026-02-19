<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Deal;
use App\Models\Payment;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        return response()->json([
            'active_clients' => Client::active()->count(),
            'total_clients' => Client::count(),
            'pending_leads' => Lead::new()->count(),
            'total_leads' => Lead::count(),
            'active_deals' => Deal::active()->count(),
            'expiring_soon' => Deal::expiringSoon(7)->count(),
            'recent_payments' => Payment::where('status', 'completed')
                ->where('created_at', '>=', now()->startOfMonth())
                ->count(),
            'revenue_mtd' => Payment::where('status', 'completed')
                ->where('created_at', '>=', now()->startOfMonth())
                ->sum('amount'),
        ]);
    }
}
