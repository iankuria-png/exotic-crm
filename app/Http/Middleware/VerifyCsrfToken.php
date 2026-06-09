<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Session-authenticated bearer-token handoff for the SPA. Protected by
        // requiring an authenticated web session inside the controller; the SPA
        // cannot carry a CSRF token across the Google OAuth redirect round-trip.
        'crm/auth/exchange',
    ];
}
