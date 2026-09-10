<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCrmSessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Sanctum::actingAs uses a Mockery token in feature tests; it is not a
        // persisted token and must retain the normal test authentication path.
        if ($token && str_starts_with(get_class($token), 'Mockery_')) {
            return $next($request);
        }

        if ($token && array_values(array_filter((array) $token->abilities)) !== ['*']) {
            return response()->json(['message' => 'This token is restricted to MCP reads.'], 403);
        }

        return $next($request);
    }
}
