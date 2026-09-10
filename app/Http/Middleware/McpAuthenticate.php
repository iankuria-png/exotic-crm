<?php

namespace App\Http\Middleware;

use App\Models\McpToolCall;
use App\Services\Mcp\McpSettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class McpAuthenticate
{
    public function __construct(private readonly McpSettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) ($request->header('X-Request-Id') ?: Str::uuid());
        $plainToken = $this->bearerToken($request);
        $token = $plainToken ? PersonalAccessToken::findToken($plainToken) : null;

        if (! $token || ! $token->tokenable) {
            $this->record($request, $requestId, null, null, 'refused', 'missing_ability');

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $abilities = array_values(array_filter((array) $token->abilities));
        $isMcp = in_array('mcp:read', $abilities, true);
        $hasWildcard = in_array('*', $abilities, true);
        $expiresAt = $token->expires_at;

        if (! $isMcp || $hasWildcard || ($expiresAt && now()->greaterThanOrEqualTo($expiresAt))) {
            $this->record($request, $requestId, $token, $token->tokenable, 'refused', $expiresAt && now()->greaterThanOrEqualTo($expiresAt) ? 'expired' : 'missing_ability');

            return response()->json(['message' => 'MCP token is invalid or expired.'], 401);
        }

        if (! $this->settings->enabled()) {
            $this->record($request, $requestId, $token, $token->tokenable, 'refused', 'server_disabled');

            return response()->json(['message' => 'MCP server is disabled.'], 503);
        }

        $rateKey = 'mcp-token:'.$token->id;
        $limit = (int) data_get($this->settings->settings(), 'limits.rate_per_minute', 30);
        if (RateLimiter::tooManyAttempts($rateKey, $limit)) {
            $this->record($request, $requestId, $token, $token->tokenable, 'throttled', 'rate_limit');

            return response()->json(['message' => 'MCP rate limit exceeded.'], 429);
        }
        RateLimiter::hit($rateKey, 60);

        Auth::guard('sanctum')->setUser($token->tokenable);
        $request->setUserResolver(fn () => $token->tokenable);
        $request->attributes->set('mcp_token', $token);
        $request->attributes->set('mcp_request_id', $requestId);

        $token->forceFill(['last_used_at' => now()])->save();

        return $next($request);
    }

    private function bearerToken(Request $request): ?string
    {
        $header = trim((string) $request->header('Authorization', ''));

        return str_starts_with(strtolower($header), 'bearer ')
            ? trim(substr($header, 7))
            : null;
    }

    private function record(Request $request, string $requestId, ?PersonalAccessToken $token, $user, string $status, string $reason): void
    {
        try {
            McpToolCall::create([
                'token_id' => $token?->id,
                'user_id' => $user?->id,
                'tool' => 'rpc:authentication',
                'argument_summary' => ['method' => $request->method(), 'path' => $request->path()],
                'status' => $status,
                'refusal_reason' => $reason,
                'request_id' => $requestId,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Authentication failures must never become 500s because audit storage is unavailable.
        }
    }
}
