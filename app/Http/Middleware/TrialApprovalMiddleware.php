<?php

namespace App\Http\Middleware;

use App\Http\Controllers\CRM\FreeTrialApprovalController;
use App\Models\Client;
use App\Models\Deal;
use Closure;
use Illuminate\Http\Request;

/** Legacy URLs remain usable; discretionary trials enter the same durable approval workflow. */
class TrialApprovalMiddleware
{
    public function handle(Request $request, Closure $next, string $action)
    {
        if (in_array($action, ['activate', 'extend', 'renew']) && $request->input('payment_method') !== 'free_trial') {
            return $next($request);
        }
        $deal = $request->route('deal');
        $client = $request->route('client');
        if ($deal && ! $deal instanceof Deal) {
            $deal = Deal::findOrFail($deal);
        }
        if ($client && ! $client instanceof Client) {
            $client = Client::findOrFail($client);
        }

        return app(FreeTrialApprovalController::class)->legacy($request, $action, $deal, $client);
    }
}
