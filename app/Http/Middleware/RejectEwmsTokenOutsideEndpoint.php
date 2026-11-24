<?php

namespace App\Http\Middleware;

use App\Services\Ewms\EwmsKpiAccess;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RejectEwmsTokenOutsideEndpoint
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && in_array($request->path(), [EwmsKpiAccess::RECORDS_PATH, EwmsKpiAccess::SUMMARY_PATH], true)) {
            return $next($request);
        }
        if ($bearer = $request->bearerToken()) {
            $token = PersonalAccessToken::findToken($bearer);
            if (in_array(EwmsKpiAccess::ABILITY, (array) $token?->abilities, true)) {
                return response()->json(['message' => 'EWMS tokens may only read the EWMS evidence endpoints.'], 403);
            }
        }

        return $next($request);
    }
}
