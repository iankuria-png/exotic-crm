<?php

namespace App\Http\Middleware;

use App\Models\Platform;
use Closure;
use Illuminate\Http\Request;

class VerifyWordPressSharedKey
{
    public function handle(Request $request, Closure $next)
    {
        if ($this->hasValidSharedKey($request) || $this->hasValidBasicCredentials($request)) {
            return $next($request);
        }

        return response()->json(['message' => 'Unauthorized'], 401);
    }

    private function hasValidSharedKey(Request $request): bool
    {
        $expected = trim((string) config('services.exotic_crm_sync.shared_key'));
        $provided = trim((string) $request->header(config('kyc.shared_key_header', 'X-Exotic-CRM-Sync-Key')));

        return $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
    }

    private function hasValidBasicCredentials(Request $request): bool
    {
        $username = (string) ($request->getUser() ?? '');
        $password = (string) ($request->getPassword() ?? '');
        if ($username === '' || $password === '') {
            return false;
        }

        return Platform::query()
            ->where('wp_api_user', $username)
            ->get()
            ->contains(fn (Platform $platform) => (string) $platform->wp_api_password === $password);
    }
}
