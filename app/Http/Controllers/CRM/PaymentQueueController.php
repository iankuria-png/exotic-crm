<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Payment;

class PaymentQueueController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['platform', 'product']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->platform_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                  ->orWhere('transaction_reference', 'like', "%{$search}%");
            });
        }

        $payments = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 25));

        return response()->json($payments);
    }
}
