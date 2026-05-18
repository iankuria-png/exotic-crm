<?php

namespace App\Http\Middleware;

use App\Models\Platform;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates incoming server-to-server requests from WordPress plugin instances.
 *
 * Required headers:
 *   X-Exotic-CRM-Sync-Key   — shared HMAC key (constant-time compared)
 *   X-Exotic-Platform-Id    — numeric platform ID, must be in allowlist
 *   X-Exotic-Timestamp      — Unix epoch, rejected if >5 min skew
 *   X-Exotic-Signature      — hash_hmac('sha256', timestamp.'.'.rawBody, key)
 *
 * On success, attaches resolved Platform to $request->attributes as 'platform'.
 */
class WpServiceAuth
{
    private const MAX_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $sharedKey = trim((string) config('services.exotic_crm_sync.shared_key', ''));

        if ($sharedKey === '') {
            Log::error('WpServiceAuth: EXOTIC_CRM_SYNC_SHARED_KEY not configured.');
            return response()->json(['error' => 'Service auth not configured.'], 503);
        }

        // --- Header extraction ---
        $providedKey = trim((string) $request->header('X-Exotic-CRM-Sync-Key', ''));
        $platformId  = (int) $request->header('X-Exotic-Platform-Id', '0');
        $timestamp   = (int) $request->header('X-Exotic-Timestamp', '0');
        $signature   = trim((string) $request->header('X-Exotic-Signature', ''));

        if ($providedKey === '' || $platformId <= 0 || $timestamp === 0 || $signature === '') {
            return response()->json(['error' => 'Missing required auth headers.'], 401);
        }

        // --- Shared key validation (constant-time) ---
        if (!hash_equals($sharedKey, $providedKey)) {
            Log::warning('WpServiceAuth: invalid shared key', ['platform_id' => $platformId]);
            return response()->json(['error' => 'Unauthorized.'], 401);
        }

        // --- Timestamp / replay protection ---
        $skew = abs(time() - $timestamp);
        if ($skew > self::MAX_SKEW_SECONDS) {
            Log::warning('WpServiceAuth: timestamp skew exceeded', [
                'platform_id' => $platformId,
                'skew'        => $skew,
            ]);
            return response()->json(['error' => 'Request timestamp expired.'], 401);
        }

        // --- HMAC signature validation ---
        $rawBody  = $request->getContent();
        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $sharedKey);

        if (!hash_equals($expected, $signature)) {
            Log::warning('WpServiceAuth: bad signature', ['platform_id' => $platformId]);
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        // --- Platform allowlist ---
        $allowlist = (array) config('services.seo_engine.platform_allowlist', []);
        if (!empty($allowlist) && !in_array($platformId, $allowlist, true)) {
            Log::warning('WpServiceAuth: platform not in allowlist', ['platform_id' => $platformId]);
            return response()->json(['error' => 'Platform not authorized for SEO service.'], 403);
        }

        // --- Resolve platform ---
        $platform = Platform::find($platformId);
        if (!$platform) {
            return response()->json(['error' => 'Platform not found.'], 404);
        }

        Log::info('WpServiceAuth: accepted', [
            'platform_id' => $platformId,
            'path'        => $request->path(),
        ]);

        $request->attributes->set('platform', $platform);
        $request->attributes->set('platform_id', $platformId);

        return $next($request);
    }
}
