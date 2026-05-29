<?php

namespace App\Http\Middleware;

use App\Services\Messaging\Sidecar\HmacSigner;
use Closure;
use Illuminate\Http\Request;

class VerifyWhatsAppSidecarHmac
{
    public function __construct(private readonly HmacSigner $signer)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $secrets = [
            config('services.whatsapp.sidecar_laravel_hmac_secret'),
            config('services.whatsapp.sidecar_laravel_hmac_secret_previous'),
        ];

        if (!$this->signer->verify($request->getContent(), $request->header('X-Signature'), $secrets)) {
            return response()->json(['message' => 'Invalid sidecar signature.'], 401);
        }

        return $next($request);
    }
}
