<?php

namespace App\Http\Middleware;

use App\Services\Messaging\Sidecar\HmacSigner;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyLegacyPaymentCallback
{
    public function __construct(private readonly HmacSigner $signer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (trim((string) $request->header('X-KopoKopo-Signature', '')) !== '') {
            $request->attributes->set('legacy_payment_callback_transport', 'kopokopo');

            return $next($request);
        }

        $callbackId = trim((string) $request->header('X-Exotic-Callback-Id', ''));
        if ($callbackId === '') {
            return response()->json([
                'message' => 'Missing callback id.',
                'error_code' => 'missing_callback_id',
            ], 422);
        }

        $secrets = array_values(array_filter([
            config('services.django.callback_secret'),
            config('services.django.callback_previous_secret'),
        ]));

        if ($secrets === [] || !$this->signer->verify(
            $request->getContent(),
            $request->header('X-Signature'),
            $secrets,
            (int) config('services.django.callback_clock_skew_seconds', 300)
        )) {
            return response()->json([
                'message' => 'Invalid payment callback signature.',
                'error_code' => 'invalid_signature',
            ], 401);
        }

        $ttl = max(60, (int) config('services.django.callback_replay_ttl_seconds', 600));
        $cacheKey = 'legacy-payment-callback:' . sha1($callbackId);
        if (!Cache::add($cacheKey, true, now()->addSeconds($ttl))) {
            return response()->json([
                'message' => 'Duplicate payment callback.',
                'error_code' => 'duplicate_callback',
            ], 409);
        }

        $request->attributes->set('legacy_payment_callback_id', $callbackId);

        return $next($request);
    }
}
