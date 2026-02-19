<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;

class ClientController extends Controller
{
    public function index(Request $request)
    {
        $query = Client::with(['platform', 'assignedAgent']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone_normalized', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            $query->where('profile_status', $request->status);
        }

        if ($request->filled('platform_id')) {
            $query->where('platform_id', $request->platform_id);
        }

        $clients = $query->orderBy('updated_at', 'desc')
            ->paginate($request->get('per_page', 25));

        return response()->json($clients);
    }

    public function show(Client $client)
    {
        $client->load(['platform', 'assignedAgent', 'deals.product', 'notes.author', 'payments']);

        return response()->json($client);
    }
}
