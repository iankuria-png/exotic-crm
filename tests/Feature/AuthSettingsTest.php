<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_auth_config_defaults_to_password_login(): void
    {
        $this->getJson('/api/crm/auth/config')
            ->assertOk()
            ->assertJsonPath('password.enabled', true)
            ->assertJsonPath('google.enabled', false);
    }

    public function test_admin_can_save_google_sso_draft_without_exposing_secret(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]));

        $this->patchJson('/api/crm/settings/auth', [
            'password_login_policy' => AuthSettingsService::PASSWORD_ENABLED,
            'google' => [
                'client_id' => 'client-id.apps.googleusercontent.com',
                'client_secret' => 'super-secret',
                'redirect_uri' => 'https://crm.example.com/auth/google/callback',
                'allowed_domains' => ['example.com'],
                'allowed_emails' => [],
                'auto_link_existing_users' => true,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('settings.google.client_secret_configured', true)
            ->assertJsonMissing(['client_secret' => 'super-secret']);
    }

    public function test_admin_cannot_restrict_password_login_before_google_test_passes(): void
    {
        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]));

        $this->patchJson('/api/crm/settings/auth', [
            'password_login_policy' => AuthSettingsService::PASSWORD_DISABLED,
            'google' => [
                'client_id' => 'client-id.apps.googleusercontent.com',
                'client_secret' => 'super-secret',
                'redirect_uri' => 'https://crm.example.com/auth/google/callback',
                'allowed_domains' => ['example.com'],
            ],
        ])->assertUnprocessable();
    }

    public function test_admin_only_password_policy_blocks_non_admin_login(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);
        $sales = User::factory()->create([
            'email' => 'sales@example.com',
            'password' => bcrypt('secret-password'),
            'role' => 'sales',
            'status' => 'active',
        ]);

        app(AuthSettingsService::class)->save([
            'password_login_policy' => AuthSettingsService::PASSWORD_ADMIN_ONLY,
        ], (int) $admin->id);

        $this->postJson('/api/crm/login', [
            'email' => $sales->email,
            'password' => 'secret-password',
        ])->assertForbidden();
    }

    public function test_crm_session_auth_can_resolve_user_on_api_requests(): void
    {
        $password = 'secret-password';
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt($password),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->postJson('/api/crm/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk();

        $this->getJson('/api/crm/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }
}
