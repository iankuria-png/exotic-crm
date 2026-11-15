<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PayLinkPrivacy
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Cache-Control','private, no-store, max-age=0');
        $response->headers->set('X-Robots-Tag','noindex, nofollow');
        $response->headers->set('Referrer-Policy','no-referrer');
        return $response;
    }
}
