<?php

namespace App\Http\Controllers\CRM;

use App\Helpers\LogHelper;
use App\Http\Controllers\Controller;
use App\Services\AuthSettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Socialite\Facades\Socialite;

class AuthSettingsController extends Controller
{
    public function __construct(
        private readonly AuthSettingsService $authSettingsService
    ) {
    }

    public function show()
    {
        return response()->json([
            'settings' => $this->authSettingsService->settings(),
            'password_policy_options' => [
                ['value' => AuthSettingsService::PASSWORD_ENABLED, 'label' => 'Enabled for everyone'],
                ['value' => AuthSettingsService::PASSWORD_ADMIN_ONLY, 'label' => 'Admins only'],
                ['value' => AuthSettingsService::PASSWORD_DISABLED, 'label' => 'Disabled'],
            ],
        ]);
    }

    public function publicConfig()
    {
        return response()->json($this->authSettingsService->publicConfig());
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'password_login_policy' => ['sometimes', 'string', Rule::in([
                AuthSettingsService::PASSWORD_ENABLED,
                AuthSettingsService::PASSWORD_ADMIN_ONLY,
                AuthSettingsService::PASSWORD_DISABLED,
            ])],
            'require_google_for_non_admin' => 'sometimes|boolean',
            'google' => 'sometimes|array',
            'google.enabled' => 'sometimes|boolean',
            'google.primary' => 'sometimes|boolean',
            'google.client_id' => 'sometimes|nullable|string|max:255',
            'google.client_secret' => 'sometimes|nullable|string|max:2000',
            'google.redirect_uri' => 'sometimes|nullable|url|max:500',
            'google.allowed_domains' => 'sometimes|array',
            'google.allowed_domains.*' => 'string|max:255',
            'google.allowed_emails' => 'sometimes|array',
            'google.allowed_emails.*' => 'email|max:255',
            'google.auto_link_existing_users' => 'sometimes|boolean',
        ]);

        $before = $this->authSettingsService->settings();
        $settings = $this->authSettingsService->save($validated, (int) $request->user()->id);

        LogHelper::record($request->user(), 'auth_settings_update', $request, [
            'before' => $this->auditSafe($before),
            'after' => $this->auditSafe($settings),
        ]);

        return response()->json(['settings' => $settings]);
    }

    public function activateGoogle(Request $request)
    {
        $before = $this->authSettingsService->settings();
        $settings = $this->authSettingsService->activateGoogle((int) $request->user()->id);

        LogHelper::record($request->user(), 'auth_google_activate', $request, [
            'before' => $this->auditSafe($before),
            'after' => $this->auditSafe($settings),
        ]);

        return response()->json(['settings' => $settings]);
    }

    public function rollback(Request $request)
    {
        $before = $this->authSettingsService->settings();
        $settings = $this->authSettingsService->rollback((int) $request->user()->id);

        LogHelper::record($request->user(), 'auth_settings_rollback', $request, [
            'before' => $this->auditSafe($before),
            'after' => $this->auditSafe($settings),
        ]);

        return response()->json(['settings' => $settings]);
    }

    public function startGoogleTest(Request $request)
    {
        $settings = $this->authSettingsService->settings();
        if (!$settings['google']['configured']) {
            return response()->json([
                'message' => 'Save Google client ID, client secret, and redirect URI before testing.',
            ], 422);
        }

        $request->session()->put('crm_google_auth_mode', 'test');
        $request->session()->put('crm_google_auth_tester_id', (int) $request->user()->id);

        $this->authSettingsService->configureGoogleProvider();
        $url = Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->redirect()
            ->getTargetUrl();

        return response()->json(['url' => $url]);
    }

    private function auditSafe(array $settings): array
    {
        unset($settings['google']['client_secret'], $settings['google']['client_secret_encrypted']);

        return $settings;
    }
}
