<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Services\AuthSettingsService;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Validation\ValidationException;

class GoogleAuthController extends Controller
{
    public function __construct(
        private readonly AuthSettingsService $authSettingsService
    ) {
    }

    // Redirect to Google
    public function redirectToGoogle(Request $request)
    {
        try {
            $settings = $this->authSettingsService->settings();
            if (!$settings['google']['enabled'] || !$settings['google']['ready']) {
                return redirect('/login?error=' . urlencode('Google login is not enabled yet.'));
            }

            $request->session()->put('crm_google_auth_mode', 'login');

            $this->authSettingsService->configureGoogleProvider();
            return Socialite::driver('google')
                ->scopes(['openid', 'email', 'profile'])
                ->redirect();
        } catch (\Exception $e) {
            return redirect('/login?error=' . urlencode('Unable to initiate Google login'));
        }
    }
    
  public function googleAuthSuccess(Request $request)
{
    // build query params
    $data = $request->query();

    // JSON encode
    $json = json_encode($data);

    return response("
        <script>
            // Send data to the opener window
            window.opener.postMessage($json, '*');
            window.close();
        </script>
    ", 200)->header('Content-Type', 'text/html');
}



    // Handle callback from Google
    public function handleGoogleCallback(Request $request)
    {
        $mode = (string) $request->session()->pull('crm_google_auth_mode', 'login');
        $testerId = (int) $request->session()->pull('crm_google_auth_tester_id', 0);

        try {
            $this->authSettingsService->configureGoogleProvider();
            $googleUser = Socialite::driver('google')->user();
            $raw = is_array($googleUser->user ?? null) ? $googleUser->user : [];
            $email = strtolower((string) $googleUser->getEmail());
            $googleSub = (string) $googleUser->getId();
            $hostedDomain = $raw['hd'] ?? null;
            $verified = (bool) ($raw['email_verified'] ?? $raw['verified_email'] ?? false);

            $this->authSettingsService->assertGoogleIdentityAllowed($email, $verified, $hostedDomain);

            if ($mode === 'test') {
                $this->authSettingsService->markGoogleTestResult(true, [
                    'email' => $email,
                    'hosted_domain' => $hostedDomain,
                    'message' => 'Google OAuth callback completed successfully.',
                ], $testerId ?: null);

                return redirect('/settings?tab=security&googleTest=success');
            }

            $settings = $this->authSettingsService->settings();
            $userQuery = User::query()->where('google_sub', $googleSub);
            if ((bool) data_get($settings, 'google.auto_link_existing_users', true)) {
                $userQuery->orWhere(function ($query) use ($email) {
                    $query->whereNull('google_sub')->where('email', $email);
                });
            }
            $user = $userQuery->first();

            if (!$user) {
                return redirect('/login?error=' . urlencode('Your Google account is not approved for CRM access.'));
            }

            if (($user->status ?? 'active') !== 'active') {
                return redirect('/login?error=' . urlencode('Your CRM account is inactive. Contact your administrator.'));
            }

            if ($user->google_sub && $user->google_sub !== $googleSub) {
                return redirect('/login?error=' . urlencode('This CRM user is linked to a different Google account.'));
            }

            if (!$user->google_sub) {
                $user->forceFill([
                    'google_sub' => $googleSub,
                    'google_linked_at' => now(),
                    'name' => $googleUser->getName() ?: $user->name,
                ])->save();
            }

            Auth::guard('web')->login($user);
            $request->session()->regenerate();

            return redirect('/login?google=success');
        } catch (ValidationException $exception) {
            if ($mode === 'test') {
                $this->authSettingsService->markGoogleTestResult(false, [
                    'message' => collect($exception->errors())->flatten()->first(),
                ], $testerId ?: null);

                return redirect('/settings?tab=security&googleTest=failed');
            }

            return redirect('/login?error=' . urlencode((string) collect($exception->errors())->flatten()->first()));
        } catch (\Exception $e) {
            if ($mode === 'test') {
                $this->authSettingsService->markGoogleTestResult(false, [
                    'message' => $e->getMessage(),
                ], $testerId ?: null);

                return redirect('/settings?tab=security&googleTest=failed');
            }

            return redirect('/login?error=' . urlencode('Google login failed'));
        }
    }




}
