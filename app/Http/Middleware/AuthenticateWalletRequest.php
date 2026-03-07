<?php

namespace App\Http\Middleware;

use App\Models\Platform;
use App\Services\BillingModeService;
use App\Services\WalletSettingsService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWalletRequest
{
    public function __construct(
        private readonly BillingModeService $billingModeService,
        private readonly WalletSettingsService $walletSettingsService
    ) {
    }

    public function handle(Request $request, Closure $next, string $mode = 'read'): Response
    {
        $platformId = (int) $request->header('X-Exotic-Platform-Id');
        if ($platformId <= 0) {
            return $this->error('Missing X-Exotic-Platform-Id header.', 'missing_platform', 401);
        }

        $platform = Platform::query()->find($platformId);
        if (!$platform) {
            return $this->error('Wallet platform was not found.', 'invalid_platform', 401);
        }

        try {
            $context = $this->billingModeService->assertWalletAvailable($platform);
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage(), 'wallet_disabled', 403);
        }

        $environment = (string) ($context['environment'] ?? 'sandbox');
        $bearerToken = trim((string) $request->bearerToken());
        if ($bearerToken === '' || !$this->walletSettingsService->verifyWpToCrmBearer($platform, $environment, $bearerToken)) {
            return $this->error('Invalid wallet bearer token.', 'invalid_bearer', 401);
        }

        $timestampHeader = trim((string) $request->header('X-Exotic-Timestamp', ''));
        if (!$this->timestampIsFresh($timestampHeader)) {
            return $this->error('Wallet request timestamp is missing or expired.', 'stale_timestamp', 401);
        }

        $idempotencyKey = trim((string) $request->header('X-Idempotency-Key', ''));
        if ($mode !== 'read') {
            if ($idempotencyKey === '') {
                return $this->error('Missing X-Idempotency-Key header.', 'missing_idempotency_key', 422);
            }

            $signature = trim((string) $request->header('X-Exotic-Signature', ''));
            $hmacSecret = $this->walletSettingsService->wpToCrmHmacSecret($platform, $environment);
            if ($signature === '' || $hmacSecret === '') {
                return $this->error('Wallet request signature is missing or not configured.', 'missing_signature', 401);
            }

            $expected = $this->signatureForRequest($request, $platformId, $timestampHeader, $idempotencyKey, $hmacSecret);
            if (!hash_equals($expected, $signature)) {
                return $this->error('Wallet request signature is invalid.', 'invalid_signature', 401);
            }
        }

        $request->attributes->set('wallet_platform', $platform);
        $request->attributes->set('wallet_context', $context);
        $request->attributes->set('wallet_environment', $environment);
        $request->attributes->set('wallet_idempotency_key', $idempotencyKey !== '' ? $idempotencyKey : null);

        return $next($request);
    }

    private function signatureForRequest(
        Request $request,
        int $platformId,
        string $timestamp,
        string $idempotencyKey,
        string $secret
    ): string {
        $payload = implode("\n", [
            $timestamp,
            strtoupper($request->getMethod()),
            '/' . ltrim($request->path(), '/'),
            (string) $platformId,
            $idempotencyKey,
            hash('sha256', (string) $request->getContent()),
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }

    private function timestampIsFresh(string $timestamp): bool
    {
        if ($timestamp === '' || !ctype_digit($timestamp)) {
            return false;
        }

        return abs(now()->timestamp - (int) $timestamp) <= 300;
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error_code' => $code,
        ], $status);
    }
}
