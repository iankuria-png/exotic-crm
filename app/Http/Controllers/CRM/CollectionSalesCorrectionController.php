<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Collections\CollectionSalesCorrection;
use App\Models\Collections\CollectionSalesItem;
use App\Services\Collections\CollectionSalesCorrectionService;
use Illuminate\Http\Request;

class CollectionSalesCorrectionController extends Controller
{
    public function standingAgreement(Request $request, int $receipt, \App\Services\Collections\CollectionStandingAgreementService $service)
    {
        abort_unless(config('collection.workspace_enabled'), 404);
        $v = $request->validate(['receipt_version' => 'required|integer|min:1', 'client_id' => 'required|integer|min:1',
            'product_price_id' => 'required|integer|min:1', 'subsidiary_client_id' => 'nullable|integer|min:1',
            'valid_until' => 'required|date|after:now', 'max_renewals' => 'required|integer|min:1|max:100',
            'reason' => 'required|string|min:3|max:500', 'request_key' => 'required|uuid', 'clear_exclusions' => 'sometimes|array|max:20',
            'clear_exclusions.*.id' => 'required|uuid', 'clear_exclusions.*.version' => 'required|integer|min:1']);
        $r = app(\App\Services\Collections\CollectionAccessService::class)->receipt($request->user(), $receipt);

        return response()->json($service->create($r, $request->user(), $v), 201)->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, string $item, CollectionSalesCorrectionService $service)
    {
        abort_unless(config('collection.workspace_enabled'), 404);
        $v = $request->validate(['item_version' => 'required|integer|min:1', 'action' => 'required|in:undo_effect,reassign,change_package',
            'reason' => 'required|string|min:3|max:500', 'client_id' => 'required_unless:action,undo_effect|integer|min:1',
            'product_price_id' => 'required_unless:action,undo_effect|integer|min:1', 'deal_id' => 'nullable|integer|min:1']);

        return response()->json($service->preview(CollectionSalesItem::findOrFail($item), $request->user(), $v))->header('Cache-Control', 'private, no-store');
    }

    public function execute(Request $request, string $correction, CollectionSalesCorrectionService $service)
    {
        abort_unless(config('collection.workspace_enabled'), 404);
        $v = $request->validate(['version' => 'required|integer|min:1', 'request_key' => 'required|uuid']);

        return response()->json($service->execute(CollectionSalesCorrection::findOrFail($correction), $request->user(), $v['request_key'], $v['version']))->header('Cache-Control', 'private, no-store');
    }
}
