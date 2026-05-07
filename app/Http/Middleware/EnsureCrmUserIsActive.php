<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCrmUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($request->is('api/crm/logout')) {
            return $next($request);
        }

        if ($user && ($user->status ?? 'active') !== 'active') {
            return response()->json([
                'message' => 'Account is inactive. Contact your administrator.',
            ], 403);
        }

        return $next($request);
    }
}
