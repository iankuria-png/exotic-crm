<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Collections\CollectionCorrection;
use App\Services\Collections\CollectionAccessService;
use App\Services\Collections\CollectionSubscriptionLinkService;
use Illuminate\Http\Request;

class CollectionSubscriptionLinkController extends Controller
{
    public function preview(Request $request, int $receipt, CollectionSubscriptionLinkService $links)
    {
        abort_unless(config('collection.workspace_enabled'), 404);
        $r = app(CollectionAccessService::class)->receipt($request->user(), $receipt);
        $input = $request->validate(['payment_id' => 'required|integer|min:1', 'expected_version' => 'required|integer|min:1',
            'expected_allocation_version' => 'required|integer|min:1']);

        return response()->json($links->preview($r, $request->user(), $input['payment_id'], $input['expected_version'], $input['expected_allocation_version']))
            ->header('Cache-Control', 'private, no-store');
    }

    public function execute(Request $request, string $link, CollectionSubscriptionLinkService $links)
    {
        abort_unless(config('collection.workspace_enabled'), 404);
        $input = $request->validate(['preview_version' => 'required|integer|min:1', 'idempotency_key' => 'required|uuid']);

        return response()->json($links->execute(CollectionCorrection::findOrFail($link), $request->user(), $input['preview_version'], $input['idempotency_key']))
            ->header('Cache-Control', 'private, no-store');
    }
}
