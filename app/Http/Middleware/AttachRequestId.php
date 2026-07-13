<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attaches a short correlation id to every request so a user-facing error
 * ("CRM-20260713-a1b2c3") can be mapped back to the matching Error Logs
 * occurrence and the Laravel log line. Header-only on the response, so it is
 * safe for every /api surface (including the Ads API) — it never touches a body.
 */
class AttachRequestId
{
    public const ATTRIBUTE = 'request_id';

    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = 'CRM-' . now()->format('Ymd') . '-' . Str::lower(Str::random(6));
        $request->attributes->set(self::ATTRIBUTE, $id);

        $response = $next($request);

        if (! $response->headers->has(self::HEADER)) {
            $response->headers->set(self::HEADER, $id);
        }

        return $response;
    }
}
