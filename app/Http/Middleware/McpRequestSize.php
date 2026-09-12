<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class McpRequestSize
{
    public function handle(Request $request, Closure $next): Response
    {
        $length = (int) $request->header('Content-Length', 0);
        if ($length > 1024 * 1024 || strlen($request->getContent()) > 1024 * 1024) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'MCP request exceeds the 1 MB limit.']], 413);
        }

        return $next($request);
    }
}
