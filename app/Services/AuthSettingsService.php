<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class AuthSettingsService
{
    public const KEY = 'crm_auth';

    public const PASSWORD_ENABLED = 'enabled';
    public const PASSWORD_ADMIN_ONLY = 'admin_only';
    public const PASSWORD_DISABLED = 'disabled';

    public function settings(bool $masked = true): array
    {
        $stored = $this->storedSettings();

        $settings = array_replace_recursive($this->defaults(), $stored);
        $settings['google']['client_secret_configured'] = $this->googleClientSecret() !== '';
        $settings['google']['configured'] = $this->googleConfigured($settings);
        $settings['google']['ready'] = $this->googleReady($settings);
        $settings['emergency_password_login'] = $this->emergencyPasswordLoginEnabled();

        if (!$masked) {
            $settings['google']['client_secret'] = $this->googleClientSecret();
            return $settings;
        }

        unset($settings['google']['client_secret'], $settings['google']['client_secret_encrypted']);

        return $settings;
    }

    public function publicConfig(): array
    {
        $settings = $this->settings();
        $passwordPolicy = (string) $settings['password_login_policy'];
        $googleEnabled = (bool) $settings['google']['enabled'] && (bool) $settings['google']['ready'];

        return [
            'password' => [
                'enabled' => $passwordPolicy !== self::PASSWORD_DISABLED || $this->emergencyPasswordLoginEnabled(),
                'policy' => $passwordPolicy,
            ],
            'google' => [
                'enabled' => $googleEnabled,
                'primary' => $googleEnabled && (bool) $settings['google']['primary'],
                'configured' => (bool) $settings['google']['configured'],
            ],
        ];
    }

    public function save(array $input, int $actorId): array
    {
        $current = $this->settings(masked: false);
        $next = $current;
        $googleConfigChanged = false;

        if (array_key_exists('password_login_policy', $input)) {
            $next['password_login_policy'] = (string) $input['password_login_policy'];
        }

        if (array_key_exists('require_google_for_non_admin', $input)) {
            $next['require_google_for_non_admin'] = (bool) $input['require_google_for_non_admin'];
        }

        $googleInput = (array) ($input['google'] ?? []);

        foreach (['enabled', 'primary', 'auto_link_existing_users'] as $key) {
            if (array_key_exists($key, $googleInput)) {
                $next['google'][$key] = (bool) $googleInput[$key];
            }
        }

        foreach (['client_id', 'redirect_uri'] as $key) {
            if (array_key_exists($key, $googleInput)) {
                $value = trim((string) $googleInput[$key]);
                $googleConfigChanged = $googleConfigChanged || $value !== (string) ($next['google'][$key] ?? '');
                $next['google'][$key] = $value;
            }
        }

        if (array_key_exists('client_secret', $googleInput) && trim((string) $googleInput['client_secret']) !== '') {
            $next['google']['client_secret_encrypted'] = Crypt::encryptString(trim((string) $googleInput['client_secret']));
            $googleConfigChanged = true;
        }

        foreach (['allowed_domains', 'allowed_emails'] as $key) {
            if (array_key_exists($key, $googleInput)) {
                $value = $this->normalizeList($googleInput[$key]);
                $googleConfigChanged = $googleConfigChanged || $value !== (array) ($next['google'][$key] ?? []);
                $next['google'][$key] = $value;
            }
        }

        if ($googleConfigChanged) {
            $next['google']['enabled'] = false;
            $next['google']['primary'] = false;
            $next['google']['last_test'] = [
                'status' => 'not_tested',
                'tested_at' => null,
                'email' => null,
                'hosted_domain' => null,
                'message' => 'Google configuration changed. Run a new live test before activation.',
            ];
        }

        $next['google']['configured'] = $this->googleConfigured($next);
        $next['google']['ready'] = $this->googleReady($next);
        $this->ensureSafePolicy($next);

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            [
                'value' => $this->persistable($next, $current),
                'updated_by' => $actorId,
            ]
        );

        return $this->settings();
    }

    public function markGoogleTestResult(bool $passed, array $payload = [], ?int $actorId = null): array
    {
        $current = $this->settings(masked: false);
        $current['google']['last_test'] = [
            'status' => $passed ? 'passed' : 'failed',
            'tested_at' => now()->toIso8601String(),
            'email' => $payload['email'] ?? null,
            'hosted_domain' => $payload['hosted_domain'] ?? null,
            'message' => $payload['message'] ?? null,
        ];

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            [
                'value' => $this->persistable($current, $current),
                'updated_by' => $actorId,
            ]
        );

        return $this->settings();
    }

    public function activateGoogle(int $actorId): array
    {
        $current = $this->settings(masked: false);

        if (!$this->googleReady($current)) {
            throw ValidationException::withMessages([
                'google' => 'Google SSO must be configured and tested before it can be activated.',
            ]);
        }

        $current['google']['enabled'] = true;
        $current['google']['primary'] = true;

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            [
                'value' => $this->persistable($current, $current),
                'updated_by' => $actorId,
            ]
        );

        return $this->settings();
    }

    public function rollback(int $actorId): array
    {
        $current = $this->settings(masked: false);
        $current['password_login_policy'] = self::PASSWORD_ENABLED;
        $current['require_google_for_non_admin'] = false;
        $current['google']['enabled'] = false;
        $current['google']['primary'] = false;

        IntegrationSetting::query()->updateOrCreate(
            ['key' => self::KEY],
            [
                'value' => $this->persistable($current, $current),
                'updated_by' => $actorId,
            ]
        );

        return $this->settings();
    }

    public function passwordLoginAllowedFor(?string $role): bool
    {
        $policy = (string) $this->settings()['password_login_policy'];

        if ($policy === self::PASSWORD_ENABLED) {
            return true;
        }

        return ($policy === self::PASSWORD_ADMIN_ONLY || $this->emergencyPasswordLoginEnabled()) && $role === 'admin';
    }

    public function googleClientSecret(): string
    {
        $stored = $this->storedSettings();
        $encrypted = (string) data_get($stored, 'google.client_secret_encrypted', '');

        if ($encrypted !== '') {
            try {
                return Crypt::decryptString($encrypted);
            } catch (\Throwable) {
                return '';
            }
        }

        return (string) config('services.google.client_secret', '');
    }

    public function googleOAuthConfig(): array
    {
        $settings = $this->settings(masked: false);

        return [
            'client_id' => (string) data_get($settings, 'google.client_id', ''),
            'client_secret' => $this->googleClientSecret(),
            'redirect' => (string) data_get($settings, 'google.redirect_uri', ''),
        ];
    }

    public function assertGoogleIdentityAllowed(string $email, bool $verified, ?string $hostedDomain): void
    {
        $settings = $this->settings();

        if (!$verified) {
            throw ValidationException::withMessages(['google' => 'Google did not verify this email address.']);
        }

        $email = strtolower(trim($email));
        $allowedEmails = (array) data_get($settings, 'google.allowed_emails', []);
        if ($allowedEmails !== [] && in_array($email, $allowedEmails, true)) {
            return;
        }

        $allowedDomains = (array) data_get($settings, 'google.allowed_domains', []);
        if ($allowedDomains === []) {
            return;
        }

        $hostedDomain = strtolower(trim((string) $hostedDomain));
        if ($hostedDomain === '' || !in_array($hostedDomain, $allowedDomains, true)) {
            throw ValidationException::withMessages(['google' => 'Use an approved company Google Workspace account.']);
        }
    }

    public function configureGoogleProvider(): void
    {
        config(['services.google' => $this->googleOAuthConfig()]);
    }

    private function defaults(): array
    {
        return [
            'password_login_policy' => config('services.crm_auth.password_login_enabled', true)
                ? self::PASSWORD_ENABLED
                : self::PASSWORD_DISABLED,
            'require_google_for_non_admin' => false,
            'google' => [
                'enabled' => false,
                'primary' => false,
                'client_id' => (string) config('services.google.client_id', ''),
                'client_secret_encrypted' => '',
                'redirect_uri' => (string) config('services.google.redirect') ?: URL::to('/auth/google/callback'),
                'allowed_domains' => $this->normalizeList(config('services.crm_auth.google_allowed_domains', '')),
                'allowed_emails' => $this->normalizeList(config('services.crm_auth.google_allowed_emails', '')),
                'auto_link_existing_users' => true,
                'last_test' => [
                    'status' => 'not_tested',
                    'tested_at' => null,
                    'email' => null,
                    'hosted_domain' => null,
                    'message' => null,
                ],
            ],
        ];
    }

    private function storedSettings(): array
    {
        $setting = IntegrationSetting::query()->where('key', self::KEY)->first();

        return is_array($setting?->value) ? $setting->value : [];
    }

    private function persistable(array $settings, array $current): array
    {
        unset($settings['google']['client_secret'], $settings['google']['client_secret_configured']);
        unset($settings['google']['configured'], $settings['google']['ready']);
        unset($settings['emergency_password_login']);

        if (empty($settings['google']['client_secret_encrypted']) && !empty($current['google']['client_secret_encrypted'])) {
            $settings['google']['client_secret_encrypted'] = $current['google']['client_secret_encrypted'];
        }

        return $settings;
    }

    private function normalizeList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/', $value) ?: [];
        }

        return collect(Arr::wrap($value))
            ->map(fn ($item) => strtolower(trim((string) $item)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function googleConfigured(array $settings): bool
    {
        return trim((string) data_get($settings, 'google.client_id')) !== ''
            && $this->googleSecretConfigured($settings)
            && trim((string) data_get($settings, 'google.redirect_uri')) !== '';
    }

    private function googleSecretConfigured(array $settings): bool
    {
        return trim((string) data_get($settings, 'google.client_secret', '')) !== ''
            || trim((string) data_get($settings, 'google.client_secret_encrypted', '')) !== ''
            || trim((string) config('services.google.client_secret', '')) !== '';
    }

    private function googleReady(array $settings): bool
    {
        return $this->googleConfigured($settings)
            && data_get($settings, 'google.last_test.status') === 'passed';
    }

    private function ensureSafePolicy(array $settings): void
    {
        $passwordPolicy = (string) $settings['password_login_policy'];
        $requiresGoogle = (bool) $settings['require_google_for_non_admin'];
        $googleEnabled = (bool) data_get($settings, 'google.enabled');

        if (($passwordPolicy === self::PASSWORD_DISABLED || $requiresGoogle || $googleEnabled) && !$this->googleReady($settings)) {
            throw ValidationException::withMessages([
                'auth' => 'Google SSO must pass a live test before password login can be restricted or Google can be enabled.',
            ]);
        }
    }

    private function emergencyPasswordLoginEnabled(): bool
    {
        return (bool) config('services.crm_auth.emergency_password_login', true);
    }
}
