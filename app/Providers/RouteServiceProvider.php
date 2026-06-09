<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // Resolve the CRM user via the bearer-token guard explicitly: the
            // default guard is `web`, which is unused on the token-first /api
            // surface, so $request->user() would always be null here.
            $user = $request->user('sanctum');

            if ($user) {
                // Authenticated app traffic legitimately bursts well past 60/min:
                // dashboard + sidebar + team pollers (15-60s each) plus the 60s
                // heartbeat. Key per user so one busy operator cannot throttle
                // teammates sharing an office IP.
                return Limit::perMinute(300)->by('crm-user:' . $user->getAuthIdentifier());
            }

            // Unauthenticated requests (login screen bootstrap: setup status,
            // auth config) are keyed by IP. Raised from 60 so a team behind a
            // single NAT cannot exhaust the bucket and break "Continue with
            // Google" for everyone.
            return Limit::perMinute(120)->by('ip:' . $request->ip());
        });
    }
}
