<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Where to send an unauthenticated caller. Nowhere: there is no server-side
     * login page to send them to.
     *
     * Every route this middleware guards is under /api/crm. The only HTML this
     * app serves is the SPA shell, which is public and owns its own /login as a
     * client-side route — there is no Laravel route named `login`, so the
     * `route('login')` this used to return threw RouteNotFoundException and
     * turned every expired session into a 500.
     *
     * Returning null is necessary but NOT sufficient on its own: the framework
     * handler falls back to `route('login')` itself when redirectTo() is null
     * (Foundation\Exceptions\Handler::unauthenticated). App\Exceptions\Handler
     * overrides that method to close the fallback; this returns null so the
     * landmine is gone from both ends.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
