<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class RejectMcpTokenOutsideEndpoint
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->path() === 'api/mcp' || $request->is('api/mcp')) {
            return $next($request);
        }

        $header = trim((string) $request->header('Authorization', ''));
        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return $next($request);
        }

        $token = PersonalAccessToken::findToken(trim(substr($header, 7)));
        $abilities = array_values(array_filter((array) ($token?->abilities ?? [])));

        if (in_array('mcp:read', $abilities, true)) {
            return response()->json(['message' => 'MCP tokens may only be used with the MCP endpoint.'], 403);
        }

        return $next($request);
    }
}
