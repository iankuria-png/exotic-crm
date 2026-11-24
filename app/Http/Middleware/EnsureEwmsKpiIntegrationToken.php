<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Ewms\EwmsKpiAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class EnsureEwmsKpiIntegrationToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        // A persisted bearer token is required even if a web session exists.
        if (! $request->bearerToken() || ! $token instanceof PersonalAccessToken || ! $token->exists) {
            return response()->json(['message' => 'An integration bearer token is required.'], 401);
        }
        if (! $user instanceof User || $user->role !== EwmsKpiAccess::ROLE || ! $user->isActive()
            || $token->abilities !== [EwmsKpiAccess::ABILITY]) {
            return response()->json(['message' => 'This account or token cannot read EWMS evidence.'], 403);
        }
        if (! config('ewms.enabled', false)) {
            return response()->json(['message' => 'EWMS payment evidence is disabled.', 'code' => 'ewms_disabled'], 503);
        }
        // Enforce this after identity checks. Route throttle middleware may be
        // reordered ahead of custom middleware by Laravel's priority sorter.
        $key = 'ewms-kpi:token:'.$token->getKey();
        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response()->json(['message' => 'EWMS read rate limit exceeded.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($key));
        }
        RateLimiter::hit($key, 60);
        $request->attributes->set('ewms_token_id', (int) $token->getKey());

        return $next($request);
    }
}
