<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Lead;

class LeadController extends Controller
{
    public function index(Request $request)
    {
        $query = Lead::with(['platform', 'assignedAgent']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_normalized', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->platform_id);
        }

        $leads = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 25));

        return response()->json($leads);
    }

    public function show(Lead $lead)
    {
        $lead->load(['platform', 'assignedAgent', 'convertedClient']);

        return response()->json($lead);
    }

    public function updateStatus(Request $request, Lead $lead)
    {
        $request->validate([
            'status' => 'required|in:new,contacted,qualified,converted,lost',
        ]);

        $lead->update([
            'status' => $request->status,
            'last_contact_at' => in_array($request->status, ['contacted', 'qualified']) ? now() : $lead->last_contact_at,
            'first_contact_at' => $lead->first_contact_at ?? ($request->status === 'contacted' ? now() : null),
        ]);

        return response()->json($lead);
    }

    public function pipeline(Request $request)
    {
        $platformId = $request->get('platform_id');

        $stages = ['new', 'contacted', 'qualified', 'converted', 'lost'];
        $pipeline = [];

        foreach ($stages as $stage) {
            $query = Lead::where('status', $stage);
            if ($platformId) {
                $query->where('platform_id', $platformId);
            }
            $pipeline[$stage] = $query->count();
        }

        return response()->json($pipeline);
    }
}
