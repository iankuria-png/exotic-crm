<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Payment;
use App\Models\Client;
use App\Services\PaymentMatchingService;

class PaymentQueueController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with(['platform', 'product', 'client']);

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

        if ($request->filled('matched')) {
            if ($request->matched === 'unmatched') {
                $query->whereNull('client_id');
            } elseif ($request->matched === 'matched') {
                $query->whereNotNull('client_id');
            }
        }

        if ($request->filled('match_confidence')) {
            $query->where('match_confidence', $request->match_confidence);
        }

        $payments = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 25));

        return response()->json($payments);
    }

    public function candidates(Payment $payment)
    {
        if (!$payment->platform_id) {
            return response()->json(['data' => []]);
        }

        $phone = $this->normalizePhone($payment->phone);

        $query = Client::where('platform_id', $payment->platform_id)
            ->select(['id', 'name', 'phone_normalized', 'email', 'city', 'profile_status', 'premium', 'featured', 'verified']);

        if ($phone) {
            $query->where('phone_normalized', $phone);
        } else {
            $query->limit(25);
        }

        $candidates = $query->orderBy('name')->get();

        return response()->json([
            'payment_id' => $payment->id,
            'normalized_phone' => $phone,
            'count' => $candidates->count(),
            'data' => $candidates,
        ]);
    }

    public function autoMatch(Payment $payment)
    {
        $service = new PaymentMatchingService();
        $result = $service->matchPayment($payment);

        return response()->json([
            'result' => $result,
            'payment' => $payment->fresh(['platform', 'product', 'client']),
        ]);
    }

    public function confirmMatch(Request $request, Payment $payment)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
        ]);

        $service = new PaymentMatchingService();
        $payment = $service->confirmMatch($payment, $validated['client_id'], $request->user()->id);
        $payment->load(['platform', 'product']);

        return response()->json($payment);
    }

    public function batchMatch(Request $request)
    {
        $service = new PaymentMatchingService();
        $results = $service->batchMatch($request->input('platform_id'));

        return response()->json($results);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        $phone = preg_replace('/[^\d+]/', '', $phone);
        $phone = ltrim($phone, '+');

        if (str_starts_with($phone, '0')) {
            $phone = '254' . substr($phone, 1);
        }

        return $phone ?: null;
    }
}
