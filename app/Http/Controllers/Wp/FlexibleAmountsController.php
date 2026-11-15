<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Services\FlexibleAmounts\FlexibleQuoteService;
use App\Services\FlexibleAmounts\FlexibleSelfCheckoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class FlexibleAmountsController extends Controller
{
    public function catalogue(Request $request)
    {
        $client = app(FlexibleSelfCheckoutService::class)->client($request);
        abort_unless(RateLimiter::attempt('flexible-catalogue:'.$client->id, 30, fn () => true, 60), 429);
        $rules = app(\App\Services\FlexibleAmounts\FlexibleRulesService::class)->rules($client->platform);
        if ($rules['mode'] === 'off' || ! $rules['surfaces']['dashboard']) {
            return response()->json(['mode' => 'off', 'plans' => []]);
        }
        $state = app(FlexibleQuoteService::class)->state($client);

        return response()->json(app(FlexibleQuoteService::class)->catalogue($client->platform, $client, 'dashboard', $state));
    }

    public function quote(Request $request)
    {
        $client = app(FlexibleSelfCheckoutService::class)->client($request);
        abort_unless(RateLimiter::attempt('flexible-quote:'.$client->id, 90, fn () => true, 60), 429);

        return response()->json(app(FlexibleQuoteService::class)->quote($client->platform, $client, $request->all()));
    }

    public function start(Request $request)
    {
        return response()->json(app(FlexibleSelfCheckoutService::class)->start($request), 201);
    }

    public function status(Request $request)
    {
        return response()->json(app(FlexibleSelfCheckoutService::class)->status($request));
    }
}
