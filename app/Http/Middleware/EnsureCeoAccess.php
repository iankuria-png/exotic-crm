<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCeoAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user
                && ($user->role ?? null) === 'admin'
                && ($user->status ?? 'active') === 'active'
                && (bool) ($user->is_ceo ?? false),
            403,
            'CEO dashboard access is restricted.'
        );

        return $next($request);
    }
}
