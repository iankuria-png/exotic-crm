<?php

namespace App\Http\Middleware;

use App\Models\Platform;
use App\Models\TourMarketSetting;
use App\Services\WalletSettingsService;
use Closure;
use Illuminate\Http\Request;

class AuthenticateTourRequest
{
    public function handle(Request $request, Closure $next)
    {
        $id = (int) $request->header('X-Exotic-Platform-Id');
        $platform = Platform::find($id);
        abort_unless($platform && $platform->is_active, 401, 'Unknown Tours market.');
        $config = TourMarketSetting::where('platform_id', $id)->first();
        abort_unless($config && ! empty($config->settings['enabled']), 403, 'Tours is unavailable for this market.');
        $environment = $config->settings['environment'] ?? 'sandbox';
        abort_unless(in_array($environment, ['sandbox', 'production'], true), 403, 'Tours mode is not configured.');
        $wallet = app(WalletSettingsService::class);
        abort_unless($request->bearerToken() && $wallet->verifyWpToCrmBearer($platform, $environment, $request->bearerToken()), 401, 'Invalid Tours bearer token.');
        $timestamp = (string) $request->header('X-Exotic-Timestamp');
        $key = (string) $request->header('X-Idempotency-Key');
        abort_unless(ctype_digit($timestamp) && abs(now()->timestamp - (int) $timestamp) <= 300 && $key !== '' && strlen($key) <= 128, 401, 'Tours request expired or missing its request key.');
        $secret = $wallet->wpToCrmHmacSecret($platform, $environment);
        $payload = implode("\n", [$timestamp, strtoupper($request->method()), '/'.ltrim($request->path(), '/'), (string) $id, $key, hash('sha256', $request->getContent())]);
        abort_unless($secret !== '' && hash_equals(hash_hmac('sha256', $payload, $secret), (string) $request->header('X-Exotic-Signature')), 401, 'Invalid Tours signature.');
        $request->attributes->set('tour_platform', $platform);
        $request->attributes->set('tour_environment', $environment);

        return $next($request);
    }
}
