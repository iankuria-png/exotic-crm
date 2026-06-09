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

    public function test_login_returns_a_bearer_token_that_authenticates_api_requests(): void
    {
        $password = 'secret-password';
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'password' => bcrypt($password),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $token = $this->postJson('/api/crm/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json('token');

        $this->assertNotEmpty($token);

        $this->withToken($token)
            ->getJson('/api/crm/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_bearer_token_still_authenticates_after_the_session_is_flushed(): void
    {
        // Regression guard for the "random logout" bug: API auth must NOT depend
        // on the first-party session. A flushed/expired session must not revoke
        // an otherwise-valid bearer token.
        $password = 'secret-password';
        $user = User::factory()->create([
            'email' => 'persists@example.com',
            'password' => bcrypt($password),
            'role' => 'admin',
            'status' => 'active',
        ]);

        $token = $this->postJson('/api/crm/login', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json('token');

        // Simulate the session lapsing out from under a long-lived tab.
        $this->flushSession();

        $this->withToken($token)
            ->getJson('/api/crm/me')
            ->assertOk()
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_api_requests_without_a_token_are_rejected(): void
    {
        // With token-first auth, a stale session cookie alone must never grant
        // access to protected /api routes.
        $this->getJson('/api/crm/me')->assertUnauthorized();
    }

    public function test_crm_me_does_not_return_a_token(): void
    {
        $user = User::factory()->create([
            'email' => 'google-admin@example.com',
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/crm/me')
            ->assertOk()
            ->assertJsonMissingPath('token')
            ->assertJsonPath('user.email', $user->email);
    }

    public function test_session_token_exchange_mints_a_bearer_token_for_authenticated_session(): void
    {
        $user = User::factory()->create([
            'email' => 'exchange-admin@example.com',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $first = $this->actingAs($user)->postJson('/crm/auth/exchange');
        $first->assertOk()
            ->assertJsonPath('user.email', $user->email);
        $this->assertNotEmpty($first->json('token'));

        // Idempotent within a session: a second call still succeeds and never
        // depends on a one-time value that an earlier request could consume.
        $second = $this->actingAs($user)->postJson('/crm/auth/exchange');
        $second->assertOk();
        $this->assertNotEmpty($second->json('token'));
    }

    public function test_session_token_exchange_requires_an_authenticated_session(): void
    {
        $this->postJson('/crm/auth/exchange')->assertUnauthorized();
    }

    public function test_session_token_exchange_rejects_inactive_accounts(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'role' => 'admin',
            'status' => 'inactive',
        ]);

        $this->actingAs($user)
            ->postJson('/crm/auth/exchange')
            ->assertForbidden();
    }
}
