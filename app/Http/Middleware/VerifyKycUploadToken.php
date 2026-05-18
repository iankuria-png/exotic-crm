<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

class VerifyKycUploadToken
{
    public function handle(Request $request, Closure $next)
    {
        $header = (string) $request->header('Authorization', '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return response()->json(['message' => 'Missing upload token.'], 401);
        }

        try {
            $claims = (array) JWT::decode($matches[1], new Key($this->jwtKey(), 'HS256'));
        } catch (\Throwable) {
            return response()->json(['message' => 'Invalid or expired upload token.'], 401);
        }

        if (($claims['sub'] ?? null) !== 'kyc_document_blob') {
            return response()->json(['message' => 'Invalid upload token scope.'], 401);
        }

        $request->attributes->set('kyc_upload_claims', $claims);

        return $next($request);
    }

    private function jwtKey(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            return base64_decode(substr($key, 7), true) ?: $key;
        }

        return $key;
    }
}
