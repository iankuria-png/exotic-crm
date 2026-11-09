<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\Collections\CollectionAccessService;
use App\Services\Collections\CollectionSalesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CollectionSalesController extends Controller
{
    public function __construct(private readonly CollectionAccessService $access, private readonly CollectionSalesService $sales) {}

    private function receipt(Request $request, int $id, bool $storage = true)
    {
        abort_unless(config('collection.workspace_enabled') && Schema::hasTable('collection_receipts'), 404);
        if ($storage) {
            abort_unless(Schema::hasTable('collection_sales_sessions'), 503, 'The sales workflow is being installed. Your receipt is saved.');
        }

        return $this->access->receipt($request->user(), $id);
    }

    public function show(Request $request, int $receipt)
    {
        return response()->json($this->sales->current($this->receipt($request, $receipt, false), $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function clientSales(Request $request, int $client)
    {
        abort_unless(config('collection.workspace_enabled') && Schema::hasTable('collection_sales_sessions'), 404);
        $profile = \App\Models\Client::findOrFail($client);
        app(\App\Services\MarketAuthorizationService::class)->ensureUserCanAccessPlatform($request->user(), $profile->platform_id);
        $accounts = $this->access->accountIds($request->user());
        $items = \App\Models\Collections\CollectionSalesItem::with('session.receipt')->where('client_id', $client)
            ->whereHas('session', fn ($q) => $q->whereIn('account_id', $accounts))->orderByDesc('created_at')->limit(20)->get();

        return response()->json(['items' => $items->map(fn ($item) => ['deal_id' => $item->deal_id, 'receipt_id' => $item->session->receipt_id,
            'reference' => $item->session->receipt->reference_norm, 'state' => $item->state, 'session_state' => $item->session->state,
            'tier' => $item->purchase['tier'], 'duration_days' => $item->purchase['duration_days']])])->header('Cache-Control', 'private, no-store');
    }

    private function input(Request $request, bool $mutating = false): array
    {
        return $request->validate(['receipt_version' => 'required|integer|min:1', 'session_version' => 'required|integer|min:0',
            'items' => 'required|array|min:1|max:20', 'items.*.client_id' => 'required|integer|min:1',
            'items.*.product_price_id' => 'required|integer|min:1', 'items.*.saved_intent_id' => 'nullable|uuid',
            'items.*.deal_id' => 'nullable|integer|min:1', 'items.*.subsidiary_client_id' => 'nullable|integer|min:1',
            'items.*.subscription_lifecycle' => 'nullable|in:new,renewal', 'items.*.subscription_lifecycle_reason' => 'nullable|string|max:1000',
            'items.*.replace_saved_price' => 'nullable|boolean',
            'items.*.discount' => 'nullable|array', 'items.*.discount.enabled' => 'nullable|boolean',
            'items.*.discount.payable_amount' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/D'],
            'items.*.discount.percentage' => 'nullable|numeric|min:1|max:99',
            'items.*.discount.pin' => ['nullable', 'regex:/^\d{4,6}$/D'],
            'items.*.replace_saved_benefit' => 'nullable|boolean', 'items.*.subsidiary_trial' => 'nullable|array',
            'items.*.subsidiary_trial.enabled' => 'nullable|boolean',
            'items.*.subsidiary_trial.platform_id' => 'required_if:items.*.subsidiary_trial.enabled,true|nullable|integer|min:1',
            'items.*.subsidiary_trial.client_id' => 'nullable|integer|min:1', 'items.*.subsidiary_trial.manual_client_id' => 'nullable|integer|min:1',
            'items.*.subsidiary_trial.duration_days' => 'required_if:items.*.subsidiary_trial.enabled,true|nullable|integer|min:1|max:365',
            'items.*.subsidiary_trial.duration_mode' => 'nullable|in:match_primary,custom',
            'items.*.subsidiary_trial.copy_region_id' => 'nullable|integer|min:1', 'items.*.subsidiary_trial.copy_city_id' => 'nullable|integer|min:1',
            'items.*.subsidiary_trial.copy_currency_id' => 'nullable|integer|min:1',
            'items.*.subsidiary_trial.create_confirmed' => 'nullable|boolean', 'items.*.subsidiary_trial.pin' => 'nullable|string|max:6',
            'items.*.subsidiary_trial.preview_token' => 'required_if:items.*.subsidiary_trial.enabled,true|nullable|string|max:60000',
            'items.*.subsidiary_trial.request_key' => 'required_if:items.*.subsidiary_trial.enabled,true|nullable|uuid',
            'leave_remainder' => 'nullable|boolean', 'remainder_reason' => 'nullable|string|min:3|max:1000',
            'request_key' => ($mutating ? 'required' : 'nullable').'|uuid', 'preview_token' => 'nullable|string|max:60000']);
    }

    public function preview(Request $request, int $receipt)
    {
        $row = $this->receipt($request, $receipt);

        return response()->json($this->sales->preview($row, $request->user(), $this->input($request)))->header('Cache-Control', 'private, no-store');
    }

    private function subsidiaryInput(Request $request): array
    {
        return $request->validate(['source_client_id' => 'required|integer|min:1', 'product_price_id' => 'required|integer|min:1',
            'platform_id' => 'nullable|integer|min:1', 'client_id' => 'nullable|integer|min:1', 'duration_days' => 'nullable|integer|min:1|max:365',
            'duration_mode' => 'nullable|in:match_primary,custom', 'copy_region_id' => 'nullable|integer|min:1', 'copy_city_id' => 'nullable|integer|min:1', 'copy_currency_id' => 'nullable|integer|min:1']);
    }

    public function subsidiaryTargets(Request $request, int $receipt, \App\Services\Collections\CollectionSalesSubsidiaryAdapter $adapter)
    {
        return response()->json($adapter->targets($this->receipt($request, $receipt), $request->user(), $this->subsidiaryInput($request)))->header('Cache-Control', 'private, no-store');
    }

    public function subsidiaryPreview(Request $request, int $receipt, \App\Services\Collections\CollectionSalesSubsidiaryAdapter $adapter)
    {
        $request->validate(['platform_id' => 'required|integer|min:1']);

        return response()->json($adapter->preview($this->receipt($request, $receipt), $request->user(), $this->subsidiaryInput($request)))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, int $receipt)
    {
        $row = $this->receipt($request, $receipt);

        return response()->json($this->sales->save($row, $request->user(), $this->input($request, true)))->header('Cache-Control', 'private, no-store');
    }

    public function activate(Request $request, int $receipt, \App\Services\Collections\CollectionSalesFulfillmentService $fulfillment)
    {
        $row = $this->receipt($request, $receipt);
        $input = $this->input($request, true);
        $request->validate(['preview_token' => 'required|string|max:60000']);

        return response()->json($fulfillment->prepare($row, $request->user(), $input), 202)->header('Cache-Control', 'private, no-store');
    }

    public function reconcile(Request $request, string $session, string $item, \App\Services\Collections\CollectionSalesFulfillmentService $fulfillment)
    {
        app(\App\Services\MarketAuthorizationService::class)->ensureManager($request->user());
        $input = $request->validate(['session_version' => 'required|integer|min:1', 'item_version' => 'required|integer|min:1',
            'request_key' => 'required|uuid', 'reason' => 'required|string|min:3|max:1000', 'evidence_reference' => 'required|string|min:3|max:191']);
        $sale = \App\Models\Collections\CollectionSalesSession::findOrFail($session);
        $this->receipt($request, $sale->receipt_id);
        $row = $sale->items()->findOrFail($item);
        $this->sales->present($sale, $request->user());
        $key = 'sale-reconcile:'.$row->id.':'.$request->user()->id.':'.$input['request_key'];
        $digest = $this->sales->digest($input);
        $prior = \Illuminate\Support\Facades\DB::table('collection_audit')->where('dedupe_key', $key)->first();
        if ($prior) {
            $saved = json_decode($prior->context, true, 512, JSON_THROW_ON_ERROR);
            abort_unless(hash_equals($saved['digest'], $digest), 409, 'This review request was already used with different details.');

            return response()->json($saved['response'])->header('Cache-Control', 'private, no-store');
        }
        abort_unless($sale->version === $input['session_version'] && $row->version === $input['item_version'], 409, 'Reload the current activation outcome before reviewing.');
        if ($row->dispatch_started_at) {
            $fulfillment->resume($row->id, true); // Look up the saved UUID; never dispatch from this action.
        }
        $response = ['session' => $this->sales->present($sale->fresh(), $request->user())];
        app(\App\Services\Collections\CollectionPurchaseService::class)->audit($sale->receipt, $request->user()->id, 'receipt_sale_outcome_reviewed',
            ['digest' => $digest, 'reason' => $input['reason'], 'evidence_reference' => $input['evidence_reference'], 'response' => $response], $key);

        return response()->json($response)->header('Cache-Control', 'private, no-store');
    }

    public function retry(Request $request, string $session, string $item, \App\Services\Collections\CollectionSalesFulfillmentService $fulfillment)
    {
        $input = $request->validate(['session_version' => 'required|integer|min:1', 'item_version' => 'required|integer|min:1',
            'request_key' => 'required|uuid', 'reason' => 'required|string|min:3|max:1000']);
        $sale = \App\Models\Collections\CollectionSalesSession::findOrFail($session);
        $this->receipt($request, $sale->receipt_id);
        $row = $sale->items()->findOrFail($item);

        return response()->json($fulfillment->retryUndispatched($row, $request->user(), $input), 202)->header('Cache-Control', 'private, no-store');
    }
}
