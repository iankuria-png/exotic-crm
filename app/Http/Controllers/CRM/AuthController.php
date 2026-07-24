<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\AuthSettingsService;
use App\Services\TeamActivityService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use App\Models\AuditLog;
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

        if (!$user) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!Hash::check($request->password, $user->password)) {
            $this->recordAuthEvent($user, CrmAuditAction::AUTH_LOGIN_FAILED, 'Incorrect password.', $request, 'password');
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (!$this->authSettingsService->passwordLoginAllowedFor((string) $user->role)) {
            $this->recordAuthEvent($user, CrmAuditAction::AUTH_LOGIN_FAILED, 'Password login is disabled for this role.', $request, 'password');
            return response()->json(['message' => 'Password login is not enabled for this account. Use Google SSO.'], 403);
        }

        if (($user->status ?? 'active') !== 'active') {
            $this->recordAuthEvent($user, CrmAuditAction::AUTH_LOGIN_FAILED, 'Account is inactive.', $request, 'password');
            return response()->json(['message' => 'Account is inactive. Contact your administrator.'], 403);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $token = $user->createToken('crm-session')->plainTextToken;

        $this->recordAuthEvent($user, CrmAuditAction::AUTH_LOGIN, 'Signed in with password.', $request, 'password');

        return response()->json([
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * Record a sign-in / sign-out event to the audit log. Auditing must never
     * break authentication, so all failures here are swallowed.
     */
    private function recordAuthEvent(User $user, string $action, string $reason, Request $request, string $method): void
    {
        try {
            AuditLog::create([
                'platform_id' => null,
                'actor_id' => $user->id,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => $user->id,
                'after_state' => [
                    'method' => $method,
                    'email' => $user->email,
                    'user_agent' => substr((string) $request->userAgent(), 0, 500),
                ],
                'reason' => $reason,
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->serializeUser($user),
        ]);
    }

    /**
     * Exchange an authenticated first-party web session for a bearer token.
     *
     * This is the deterministic handoff used after Google SSO: the OAuth
     * callback logs the user into the web session, then the SPA calls this
     * route (via the session-aware web client) exactly once to obtain a token.
     * It is idempotent within a session and is the ONLY consumer of the login
     * token, so it can never lose a race with /crm/me.
     */
    public function exchangeSessionToken(Request $request)
    {
        $user = Auth::guard('web')->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (($user->status ?? 'active') !== 'active') {
            $this->recordAuthEvent($user, CrmAuditAction::AUTH_LOGIN_FAILED, 'Account is inactive.', $request, 'sso');
            Auth::guard('web')->logout();
            return response()->json(['message' => 'Account is inactive. Contact your administrator.'], 403);
        }

        $token = $user->createToken('crm-session')->plainTextToken;

        $this->recordAuthEvent($user, CrmAuditAction::AUTH_LOGIN, 'Signed in with Google SSO.', $request, 'sso');

        return response()->json([
            'token' => $token,
            'user' => $this->serializeUser($user),
        ]);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'is_ceo' => (bool) ($user->is_ceo ?? false)
                && ($user->role ?? null) === 'admin'
                && ($user->status ?? 'active') === 'active',
            'status' => $user->status ?? 'active',
            'assigned_market_ids' => $user->assignedMarketIds(),
            'capabilities' => $this->capabilitiesForRole((string) $user->role),
        ];
    }

    private function capabilitiesForRole(string $role): array
    {
        return [
            'field_sales_workspace' => $role === 'field_sales',
            'field_sales_client_access' => in_array($role, ['admin', 'sub_admin', 'sales', 'field_sales'], true),
            'field_sales_trial_activation' => in_array($role, ['admin', 'field_sales'], true),
            'field_sales_commissions' => in_array($role, ['admin', 'sub_admin', 'field_sales'], true),
            'settings_manage_field_sales' => in_array($role, ['admin', 'sub_admin'], true),
        ];
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

        $this->recordAuthEvent($request->user(), CrmAuditAction::AUTH_LOGOUT, 'Signed out.', $request, 'session');

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
