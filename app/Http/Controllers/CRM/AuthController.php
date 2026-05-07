<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\AuthSettingsService;
use App\Services\TeamActivityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class AuthController extends Controller
{
    public function __construct(
        private readonly TeamActivityService $teamActivityService,
        private readonly AuthSettingsService $authSettingsService
    ) {
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$this->authSettingsService->passwordLoginAllowedFor((string) $user->role)) {
            return response()->json(['message' => 'Password login is not enabled for this account. Use Google SSO.'], 403);
        }

        if (($user->status ?? 'active') !== 'active') {
            return response()->json(['message' => 'Account is inactive. Contact your administrator.'], 403);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $token = $user->createToken('crm-session')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status ?? 'active',
            ],
        ]);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status ?? 'active',
            ],
        ]);
    }

    public function logout(Request $request)
    {
        $validated = $request->validate([
            'session_token' => 'nullable|string|max:36',
        ]);

        if (!empty($validated['session_token'])) {
            $this->teamActivityService->closeUserSession(
                $request->user(),
                (string) $validated['session_token']
            );
        }

        $request->user()->currentAccessToken()?->delete();
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out']);
    }

    public function consumeImpersonationBridge(Request $request, string $bridge)
    {
        $payload = Cache::pull('crm_impersonation_bridge:' . $bridge);

        if (!is_array($payload)) {
            abort(410, 'This CRM impersonation link has expired.');
        }

        return response()->view('crm-impersonation-bridge', [
            'payload' => $payload,
        ]);
    }
}
