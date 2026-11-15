<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Platform;
use App\Services\FlexibleAmounts\FlexibleRulesService;
use App\Services\MarketAuthorizationService;
use Illuminate\Http\Request;

class FlexibleAmountsController extends Controller
{
    private function market(Request $request, int $id): Platform
    {
        abort_unless(\App\Billing\BillingPermissions::canViewSubscriptionRules($request->user()), 403);
        app(MarketAuthorizationService::class)->ensureUserCanAccessPlatform($request->user(), $id);

        return Platform::findOrFail($id);
    }

    public function show(Request $request, int $market)
    {
        return response()->json(app(FlexibleRulesService::class)->summary($this->market($request, $market)));
    }

    public function preview(Request $request, int $market)
    {
        $platform = $this->market($request, $market);
        $summary = app(FlexibleRulesService::class)->summary($platform, $request->input('flexible_amounts', []));
        if ($request->has('customer_preview')) {
            $input = $request->validate(['customer_preview.product_id' => 'required|integer', 'customer_preview.amount' => 'required|numeric|min:0|max:999999999']);
            $plan = collect(app(FlexibleRulesService::class)->catalogue($platform))->firstWhere('product_id', (int) $input['customer_preview']['product_id']);
            abort_unless($plan && $plan['eligible'], 422, 'This plan cannot be previewed.');
            $summary['customer_quote'] = (new \App\Services\FlexibleAmounts\FlexiblePricingEngine($plan, $summary['rules']))->quote((string) $input['customer_preview']['amount']) + ['plan' => $plan['plan'], 'currency' => $plan['currency']];
        }

        return response()->json($summary);
    }
}
