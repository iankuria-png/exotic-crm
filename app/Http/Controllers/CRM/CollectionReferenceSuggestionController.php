<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Deal;
use App\Services\Collections\CollectionReferenceSuggestionService;
use App\Services\MarketAuthorizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CollectionReferenceSuggestionController extends Controller
{
    public function index(Request $request, Client $client, CollectionReferenceSuggestionService $suggestions)
    {
        app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($request->user(), (int) $client->platform_id);
        $input = $request->validate([
            'deal_id' => 'required|integer|min:1',
            'amount_minor' => 'nullable|integer|min:1|max:99999999999999',
            'search' => 'nullable|string|max:128',
            'cursor' => 'nullable|string|max:8192',
        ]);
        $deal = Deal::where('client_id', $client->id)->where('platform_id', $client->platform_id)->findOrFail($input['deal_id']);
        if (! config('collection.workspace_enabled') || ! Schema::hasTable('collection_accounts')) {
            return response()->json(['enabled' => false, 'items' => [], 'scan_complete' => true, 'next_cursor' => null])->header('Cache-Control', 'private, no-store');
        }

        return response()->json($suggestions->forClient($request->user(), $client, $deal, $input))->header('Cache-Control', 'private, no-store');
    }
}
