<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientCredentialDispatch;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\CredentialDeliveryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class ClientAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_marketing_can_view_access_context_with_capability_flags(): void
    {
        $platform = Platform::factory()->create([
            'db_host' => null,
            'db_name' => null,
            'db_user' => null,
            'db_pass' => null,
            'domain' => 'kenya.example.test',
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => null,
        ]);

        Sanctum::actingAs($this->createUser('marketing', [$platform->id]));

        $response = $this->getJson("/api/crm/clients/{$client->id}/access-context");

        $response->assertOk()
            ->assertJsonPath('wp_username', null)
            ->assertJsonPath('login_url', 'https://kenya.example.test/wp-login.php')
            ->assertJsonPath('setup_url', 'https://kenya.example.test/wp-login.php?action=lostpassword')
            ->assertJsonPath('profile_url', 'https://kenya.example.test/?p=8517')
            ->assertJsonPath('can_reset_password', false)
            ->assertJsonPath('can_generate_session_link', true)
            ->assertJsonPath('messages.reset_password', CredentialDeliveryService::RESET_PASSWORD_DISABLED_MESSAGE)
            ->assertJsonPath('messages.login_as_client', null)
            ->assertJsonPath('messages.access_links', null);
    }

    public function test_admin_sub_admin_and_sales_can_reset_credentials_without_persisting_plaintext(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
        ]);

        $this->mock(CredentialDeliveryService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('resetCredentials')
                ->times(3)
                ->andReturn([
                    'access_context' => [
                        'wp_username' => 'flora-client',
                        'login_url' => 'https://kenya.example.test/wp-login.php',
                        'setup_url' => 'https://kenya.example.test/wp-login.php?action=lostpassword',
                        'profile_url' => 'https://kenya.example.test/?p=8517',
                        'can_reset_password' => true,
                        'can_generate_session_link' => true,
                        'messages' => [
                            'reset_password' => null,
                            'login_as_client' => null,
                            'access_links' => null,
                        ],
                    ],
                    'revealed' => [
                        'password' => 'TempPass123!',
                    ],
                ]);
        });

        foreach (['admin', 'sub_admin', 'sales'] as $role) {
            Sanctum::actingAs($this->createUser($role, $role === 'admin' ? [] : [$platform->id]));

            $response = $this->postJson("/api/crm/clients/{$client->id}/credentials/reset", [
                'reason' => "Reset for {$role} verification",
            ]);

            $response->assertOk()
                ->assertJsonPath('access_context.wp_username', 'flora-client')
                ->assertJsonPath('revealed.password', 'TempPass123!');
        }

        $auditLogs = AuditLog::query()
            ->where('action', 'client_credential_reset')
            ->orderBy('id')
            ->get();

        $timelineEvents = TimelineEvent::query()
            ->where('event_type', 'client_credentials_reset')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $auditLogs);
        $this->assertCount(3, $timelineEvents);
        $this->assertSame(0, ClientCredentialDispatch::query()->count());

        foreach ($auditLogs as $auditLog) {
            $this->assertStringNotContainsString('TempPass123!', json_encode($auditLog->after_state));
            $this->assertSame(12, data_get($auditLog->after_state, 'password_length'));
        }

        foreach ($timelineEvents as $timelineEvent) {
            $this->assertStringNotContainsString('TempPass123!', json_encode($timelineEvent->content));
            $this->assertSame(12, data_get($timelineEvent->content, 'password_length'));
        }
    }

    public function test_marketing_and_out_of_market_users_cannot_reset_credentials(): void
    {
        $platform = Platform::factory()->create();
        $otherPlatform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
        ]);

        Sanctum::actingAs($this->createUser('marketing', [$platform->id]));

        $this->postJson("/api/crm/clients/{$client->id}/credentials/reset", [
            'reason' => 'Marketing should not mutate credentials',
        ])->assertForbidden();

        Sanctum::actingAs($this->createUser('sales', [$otherPlatform->id]));

        $this->getJson("/api/crm/clients/{$client->id}/access-context")->assertForbidden();

        $this->postJson("/api/crm/clients/{$client->id}/credentials/reset", [
            'reason' => 'Out of market sales should be blocked',
        ])->assertForbidden();
    }

    public function test_admin_sub_admin_and_sales_can_generate_client_session_links_without_persisting_token(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
        ]);

        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            $baseUrl.'/clients/8517/session-link' => Http::response([
                'url' => 'https://kenya.example.test/?crm_client_session=super-secret-token',
                'expires_at' => '2026-04-03T08:45:00+00:00',
                'target' => 'edit_profile',
            ], 200),
        ]);

        foreach (['admin', 'sub_admin', 'sales'] as $role) {
            Sanctum::actingAs($this->createUser($role, $role === 'admin' ? [] : [$platform->id]));

            $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client", [
                'target' => 'edit_profile',
                'reason' => "Generate client session for {$role}",
            ]);

            $response->assertOk()
                ->assertJsonPath('mode', 'session')
                ->assertJsonPath('session_link_generated', true)
                ->assertJsonPath('url', 'https://kenya.example.test/?crm_client_session=super-secret-token')
                ->assertJsonPath('expires_at', '2026-04-03T08:45:00+00:00')
                ->assertJsonPath('target', 'edit_profile');
        }

        Http::assertSentCount(3);

        $auditLogs = AuditLog::query()
            ->where('action', 'client_login_as_client_link')
            ->orderBy('id')
            ->get();

        $timelineEvents = TimelineEvent::query()
            ->where('event_type', 'client_login_as_client_link_generated')
            ->orderBy('id')
            ->get();

        $this->assertCount(3, $auditLogs);
        $this->assertCount(3, $timelineEvents);

        foreach ($auditLogs as $auditLog) {
            $payload = json_encode($auditLog->after_state);
            $this->assertStringNotContainsString('crm_client_session', $payload);
            $this->assertStringNotContainsString('super-secret-token', $payload);
            $this->assertSame('edit_profile', data_get($auditLog->after_state, 'target'));
        }

        foreach ($timelineEvents as $timelineEvent) {
            $payload = json_encode($timelineEvent->content);
            $this->assertStringNotContainsString('crm_client_session', $payload);
            $this->assertStringNotContainsString('super-secret-token', $payload);
            $this->assertSame('edit_profile', data_get($timelineEvent->content, 'target'));
        }
    }

    public function test_client_session_default_target_is_profile_and_alternates_pass_through(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://uganda.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
        ]);
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');
        $targets = [];

        Http::fake(function ($request) use (&$targets, $baseUrl) {
            if ($request->url() !== $baseUrl.'/clients/8517/session-link') {
                return Http::response([], 404);
            }

            $target = (string) data_get($request->data(), 'target');
            $targets[] = $target;

            return Http::response([
                'url' => "https://uganda.example.test/?crm_client_session={$target}",
                'expires_at' => '2026-04-03T08:45:00+00:00',
                'target' => $target,
            ], 200);
        });

        Sanctum::actingAs($this->createUser('sales', [$platform->id]));

        $this->postJson("/api/crm/clients/{$client->id}/login-as-client", [
            'reason' => 'Default target should be profile',
        ])->assertOk()
            ->assertJsonPath('target', 'profile');

        foreach (['edit_profile', 'change_password', 'home'] as $target) {
            $this->postJson("/api/crm/clients/{$client->id}/login-as-client", [
                'target' => $target,
                'reason' => "Alternate target {$target}",
            ])->assertOk()
                ->assertJsonPath('target', $target);
        }

        $this->assertSame(['profile', 'edit_profile', 'change_password', 'home'], $targets);
    }

    private function fakeHealthySessionMarket(string $consumerUrl, string $landingUrl, string $baseUrl, array $overrides = []): void
    {
        Http::fake(function ($request) use ($baseUrl, $consumerUrl, $landingUrl, $overrides) {
            $url = $request->url();

            if ($url === $baseUrl.'/clients/8517/session-link') {
                return Http::response(array_merge([
                    'url' => $consumerUrl,
                    'expires_at' => '2026-04-03T08:45:00+00:00',
                    'target' => 'profile',
                    'resolved_target' => 'profile',
                    'target_url' => $landingUrl,
                    'profile_url' => $landingUrl,
                    'edit_profile_url' => 'https://kenya.example.test/edit-profile/',
                    'change_password_url' => 'https://kenya.example.test/change-password/',
                    'target_fallback_used' => false,
                ], $overrides), 200);
            }

            if ($url === $baseUrl) {
                return Http::response(['namespace' => 'exotic-crm-sync/v1'], 200);
            }

            if ($url === $baseUrl.'/clients/8517') {
                return Http::response(['post_author' => 9001, 'post_status' => 'publish'], 200);
            }

            if ($url === $consumerUrl) {
                return Http::response('', 302, [
                    'Location' => $landingUrl,
                    'Set-Cookie' => 'wordpress_logged_in_9f2=secret-cookie; path=/; domain=kenya.example.test; secure',
                    'Content-Type' => 'text/html; charset=UTF-8',
                ]);
            }

            if ($url === $landingUrl) {
                return Http::response('<html><body class="logged-in"><div id="wpadminbar"></div></body></html>', 200, [
                    'Content-Type' => 'text/html; charset=UTF-8',
                ]);
            }

            // admin-post.php reachability probe and anything else.
            return Http::response('', 200);
        });
    }

    public function test_client_session_debug_traces_a_healthy_pipeline_without_exposing_secrets(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
            'wp_profile_permalink' => 'https://kenya.example.test/escort/tracy/',
        ]);
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');
        $consumerUrl = 'https://kenya.example.test/wp-admin/admin-post.php?action=exotic_crm_client_session&token=super-secret-token';

        $this->fakeHealthySessionMarket($consumerUrl, 'https://kenya.example.test/escort/tracy/', $baseUrl);

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
            'reason' => 'Debug Tracy client session',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.status', 'pass')
            ->assertJsonPath('diagnostics.overall.failing_stage', null)
            ->assertJsonPath('diagnostics.stages.0.key', 'client_record')
            ->assertJsonPath('diagnostics.stages.0.status', 'pass')
            ->assertJsonPath('diagnostics.stages.4.key', 'session_link_mint')
            ->assertJsonPath('diagnostics.stages.4.status', 'pass')
            ->assertJsonPath('diagnostics.stages.6.key', 'token_consumed')
            ->assertJsonPath('diagnostics.stages.6.status', 'pass')
            ->assertJsonPath('diagnostics.stages.9.key', 'landing_session')
            ->assertJsonPath('diagnostics.stages.9.status', 'pass')
            // Legacy flat block stays readable for existing consumers.
            ->assertJsonPath('diagnostics.wordpress.response.url_shape', 'admin_post_consumer')
            ->assertJsonPath('diagnostics.wordpress.response.query_keys.0', 'action')
            ->assertJsonPath('diagnostics.wordpress.response.query_keys.1', 'token')
            ->assertJsonPath('diagnostics.probe.status', 302)
            ->assertJsonPath('diagnostics.probe.has_set_cookie', true);

        $payload = json_encode($response->json());
        $this->assertStringNotContainsString('super-secret-token', $payload);
        $this->assertStringNotContainsString('secret-cookie', $payload);
    }

    public function test_client_session_debug_is_restricted_to_admins(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
        ]);

        Sanctum::actingAs($this->createUser('sales', [$platform->id]));

        $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ])->assertForbidden();
    }

    public function test_client_session_debug_names_the_stage_that_breaks_the_login(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
        ]);
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');
        $consumerUrl = 'https://kenya.example.test/wp-admin/admin-post.php?action=exotic_crm_client_session&token=expired-token';

        Http::fake(function ($request) use ($baseUrl, $consumerUrl) {
            $url = $request->url();

            if ($url === $baseUrl.'/clients/8517/session-link') {
                return Http::response([
                    'url' => $consumerUrl,
                    'expires_at' => '2026-04-03T08:45:00+00:00',
                    'target' => 'profile',
                    'resolved_target' => 'profile',
                    'target_url' => 'https://kenya.example.test/escort/tracy/',
                ], 200);
            }

            if ($url === $baseUrl.'/clients/8517') {
                return Http::response(['post_author' => 9001], 200);
            }

            if ($url === $consumerUrl) {
                return Http::response(
                    '<html><body>This client session link is invalid or has expired.</body></html>',
                    403,
                    ['Content-Type' => 'text/html; charset=UTF-8']
                );
            }

            return Http::response('', 200);
        });

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.status', 'fail')
            ->assertJsonPath('diagnostics.overall.failing_stage', 'token_consumed')
            ->assertJsonPath('diagnostics.stages.6.status', 'fail')
            // Everything downstream of the break is reported as unreached, not failed.
            ->assertJsonPath('diagnostics.stages.7.status', 'skipped')
            ->assertJsonPath('diagnostics.stages.9.status', 'skipped');

        $this->assertStringContainsString('expired', strtolower((string) $response->json('diagnostics.overall.root_cause')));
        $this->assertStringNotContainsString('expired-token', json_encode($response->json()));
    }

    public function test_client_session_debug_flags_a_cdn_challenge_in_front_of_wordpress(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
        ]);

        Http::fake(fn () => Http::response(
            '<html><head><title>Just a moment...</title></head><body>Checking your browser</body></html>',
            403,
            ['Server' => 'cloudflare', 'Content-Type' => 'text/html']
        ));

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.status', 'fail')
            ->assertJsonPath('diagnostics.overall.failing_stage', 'rest_reachable');

        $this->assertStringContainsString(
            'Cloudflare',
            (string) $response->json('diagnostics.stages.2.facts.4.value')
        );
    }

    public function test_client_session_debug_separates_a_slow_endpoint_from_an_unreachable_one(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
        ]);

        // cURL 28 means the connection opened and WordPress simply never
        // answered. Reporting that as "cannot reach the site" sent people
        // chasing DNS for what was actually a slow market.
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received'
        ));

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.failing_stage', 'rest_reachable');

        $summary = (string) $response->json('diagnostics.stages.2.summary');
        $hint = (string) $response->json('diagnostics.stages.2.hint');

        $this->assertStringContainsString('did not answer within', $summary);
        $this->assertStringContainsString('slowness rather than an unreachable host', $hint);
        $this->assertStringNotContainsString('Check DNS', $hint);
    }

    public function test_client_session_debug_reports_a_genuine_connection_failure_as_such(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
        ]);

        Http::fake(fn () => throw new ConnectionException(
            'cURL error 6: Could not resolve host: kenya.example.test'
        ));

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.failing_stage', 'rest_reachable');

        $this->assertStringContainsString(
            'Check DNS',
            (string) $response->json('diagnostics.stages.2.hint')
        );
    }
    /**
     * @return array{0: \App\Models\Platform, 1: \App\Models\Client, 2: string, 3: string}
     */
    private function sessionDebugFixture(string $host = 'kenya.example.test'): array
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => "https://{$host}/wp-json/exotic-crm-sync/v1",
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
        ]);

        return [
            $platform,
            $client,
            rtrim((string) $platform->wp_api_url, '/'),
            "https://{$host}/wp-admin/admin-post.php?action=exotic_crm_client_session&token=probe-token",
        ];
    }

    private function fakeConsumerResponse(string $baseUrl, string $consumerUrl, array $consumerHeaders): void
    {
        Http::fake(function ($request) use ($baseUrl, $consumerUrl, $consumerHeaders) {
            $url = $request->url();

            if ($url === $baseUrl.'/clients/8517/session-link') {
                return Http::response([
                    'url' => $consumerUrl,
                    'target' => 'profile',
                    'resolved_target' => 'profile',
                    'target_url' => 'https://kenya.example.test/escort/tracy/',
                ], 200);
            }

            if ($url === $consumerUrl) {
                return Http::response('', 302, $consumerHeaders);
            }

            return Http::response('', 200);
        });
    }

    public function test_client_session_debug_separates_a_stripped_cookie_from_a_rejected_one(): void
    {
        [$platform, $client, $baseUrl, $consumerUrl] = $this->sessionDebugFixture();

        // A 302 with no Set-Cookie at all: headers were clearly not already
        // sent, so the cause is suppression or CDN stripping — not output.
        $this->fakeConsumerResponse($baseUrl, $consumerUrl, [
            'Location' => 'https://kenya.example.test/escort/tracy/',
            'CF-Cache-Status' => 'HIT',
        ]);

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.failing_stage', 'auth_cookie')
            ->assertJsonPath('diagnostics.stages.7.summary', 'WordPress issued the redirect but sent no Set-Cookie header at all.');

        $hint = (string) $response->json('diagnostics.stages.7.hint');
        $this->assertStringContainsString('send_auth_cookies', $hint);
        $this->assertStringContainsString('pluggable', $hint);
        $this->assertStringNotContainsString('output before the redirect', $hint);

        // CF-Cache-Status HIT means the response WAS cached, so the CDN stays
        // a live suspect and must not be acquitted.
        $this->assertStringContainsString('check whether a CDN', $hint);
    }

    public function test_client_session_debug_names_the_plugin_suppressing_auth_cookies(): void
    {
        [$platform, $client, $baseUrl, $consumerUrl] = $this->sessionDebugFixture();

        Http::fake(function ($request) use ($baseUrl, $consumerUrl) {
            $url = $request->url();

            if ($url === $baseUrl.'/clients/8517/session-link') {
                return Http::response([
                    'url' => $consumerUrl,
                    'target' => 'profile',
                    'resolved_target' => 'profile',
                    'target_url' => 'https://kenya.example.test/escort/tracy/',
                ], 200);
            }

            if ($url === $baseUrl.'/session-doctor') {
                return Http::response([
                    'send_auth_cookies_filters' => [[
                        'priority' => 10,
                        'callback' => '__return_false',
                        'file' => 'wp-content/plugins/simple-jwt-login/src/Login.php',
                        'line' => 88,
                        'plugin' => 'simple-jwt-login',
                    ]],
                    'pluggable' => [
                        'wp_set_auth_cookie' => ['defined' => true, 'is_core' => true, 'plugin' => ''],
                        'wp_clear_auth_cookie' => ['defined' => true, 'is_core' => true, 'plugin' => ''],
                    ],
                    'cookies' => ['cookie_domain' => 'www.kenya.example.test'],
                    'site' => ['is_ssl' => true],
                ], 200);
            }

            if ($url === $consumerUrl) {
                return Http::response('', 302, [
                    'Location' => 'https://kenya.example.test/escort/tracy/',
                    'CF-Cache-Status' => 'DYNAMIC',
                ]);
            }

            return Http::response('', 200);
        });

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.failing_stage', 'auth_cookie');

        $hint = (string) $response->json('diagnostics.stages.7.hint');
        $this->assertStringContainsString('simple-jwt-login', $hint);

        $facts = json_encode($response->json('diagnostics.stages.7.facts'), JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString('simple-jwt-login/src/Login.php:88', $facts);
        $this->assertStringContainsString('__return_false', $facts);
    }

    public function test_client_session_debug_flags_a_pluggable_override_of_the_cookie_functions(): void
    {
        [$platform, $client, $baseUrl, $consumerUrl] = $this->sessionDebugFixture();

        Http::fake(function ($request) use ($baseUrl, $consumerUrl) {
            $url = $request->url();

            if ($url === $baseUrl.'/clients/8517/session-link') {
                return Http::response([
                    'url' => $consumerUrl,
                    'target' => 'profile',
                    'target_url' => 'https://kenya.example.test/escort/tracy/',
                ], 200);
            }

            if ($url === $baseUrl.'/session-doctor') {
                return Http::response([
                    'send_auth_cookies_filters' => [],
                    'pluggable' => [
                        // Forcing the filter cannot help when core's function
                        // never loaded in the first place.
                        'wp_set_auth_cookie' => [
                            'defined' => true,
                            'is_core' => false,
                            'file' => 'wp-content/mu-plugins/headless-auth.php',
                            'plugin' => 'mu-plugin',
                        ],
                        'wp_clear_auth_cookie' => ['defined' => true, 'is_core' => true, 'plugin' => ''],
                    ],
                ], 200);
            }

            if ($url === $consumerUrl) {
                return Http::response('', 302, ['Location' => 'https://kenya.example.test/escort/tracy/']);
            }

            return Http::response('', 200);
        });

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.failing_stage', 'auth_cookie');

        $facts = json_encode($response->json('diagnostics.stages.7.facts'));
        $this->assertStringContainsString('REPLACED by mu-plugin', $facts);
        $this->assertStringContainsString('mu-plugin', (string) $response->json('diagnostics.stages.7.hint'));
    }

    public function test_client_session_debug_tells_you_to_upload_the_plugin_when_the_doctor_is_missing(): void
    {
        [$platform, $client, $baseUrl, $consumerUrl] = $this->sessionDebugFixture();

        Http::fake(function ($request) use ($baseUrl, $consumerUrl) {
            $url = $request->url();

            if ($url === $baseUrl.'/clients/8517/session-link') {
                return Http::response([
                    'url' => $consumerUrl,
                    'target' => 'profile',
                    'target_url' => 'https://kenya.example.test/escort/tracy/',
                ], 200);
            }

            if ($url === $baseUrl.'/session-doctor') {
                return Http::response(['code' => 'rest_no_route'], 404);
            }

            if ($url === $consumerUrl) {
                return Http::response('', 302, ['Location' => 'https://kenya.example.test/escort/tracy/']);
            }

            return Http::response('', 200);
        });

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.failing_stage', 'auth_cookie');

        $this->assertStringContainsString(
            'not installed on this market',
            json_encode($response->json('diagnostics.stages.7.facts'))
        );
    }

    public function test_client_session_debug_acquits_the_cdn_when_it_did_not_cache_the_response(): void
    {
        [$platform, $client, $baseUrl, $consumerUrl] = $this->sessionDebugFixture();

        $this->fakeConsumerResponse($baseUrl, $consumerUrl, [
            'Location' => 'https://kenya.example.test/escort/tracy/',
            'CF-Cache-Status' => 'DYNAMIC',
            'Cache-Control' => 'no-cache, must-revalidate, max-age=0, no-store, private',
        ]);

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.failing_stage', 'auth_cookie');

        $hint = (string) $response->json('diagnostics.stages.7.hint');
        $this->assertStringContainsString('CDN is not the cause here', $hint);
        $this->assertStringContainsString('DYNAMIC', $hint);
    }

    public function test_client_session_debug_reports_a_login_cookie_scoped_to_the_wrong_host(): void
    {
        [$platform, $client, $baseUrl, $consumerUrl] = $this->sessionDebugFixture();

        // WordPress sends a perfectly good cookie — for the wrong host. A
        // browser discards it silently, which is what "lands logged out" is.
        $this->fakeConsumerResponse($baseUrl, $consumerUrl, [
            'Location' => 'https://kenya.example.test/escort/tracy/',
            'Set-Cookie' => 'wordpress_logged_in_9f2=value; path=/; domain=www.other-host.test; secure; HttpOnly',
        ]);

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.failing_stage', 'auth_cookie');

        $summary = (string) $response->json('diagnostics.stages.7.summary');
        $hint = (string) $response->json('diagnostics.stages.7.hint');

        $this->assertStringContainsString('scoped to a host the browser will reject', $summary);
        $this->assertStringContainsString('www.other-host.test', $hint);
        $this->assertStringContainsString('kenya.example.test', $hint);
        $this->assertStringNotContainsString('=value', json_encode($response->json()));
    }

    public function test_client_session_debug_reports_a_cleared_but_never_issued_login_cookie(): void
    {
        [$platform, $client, $baseUrl, $consumerUrl] = $this->sessionDebugFixture();

        // wp_clear_auth_cookie() ran; wp_set_auth_cookie() was suppressed. The
        // jar drops expired cookies, so only the raw headers reveal this.
        $this->fakeConsumerResponse($baseUrl, $consumerUrl, [
            'Location' => 'https://kenya.example.test/escort/tracy/',
            'Set-Cookie' => 'wordpress_logged_in_9f2=deleted; expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0; path=/',
        ]);

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.failing_stage', 'auth_cookie')
            ->assertJsonPath('diagnostics.stages.7.summary', 'WordPress only cleared cookies — it never issued a login cookie.');

        $this->assertStringContainsString(
            'send_auth_cookies',
            (string) $response->json('diagnostics.stages.7.hint')
        );
    }
    public function test_client_session_debug_detects_a_www_apex_cookie_split(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://www.kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
        ]);
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');
        $consumerUrl = 'https://www.kenya.example.test/wp-admin/admin-post.php?action=exotic_crm_client_session&token=split-token';
        // The cookie is issued on www, but WordPress sends the browser to the apex.
        $landingUrl = 'https://kenya.example.test/escort/tracy/';

        Http::fake(function ($request) use ($baseUrl, $consumerUrl, $landingUrl) {
            $url = $request->url();

            if ($url === $baseUrl.'/clients/8517/session-link') {
                return Http::response([
                    'url' => $consumerUrl,
                    'target' => 'profile',
                    'resolved_target' => 'profile',
                    'target_url' => $landingUrl,
                ], 200);
            }

            if ($url === $consumerUrl) {
                return Http::response('', 302, [
                    'Location' => $landingUrl,
                    'Set-Cookie' => 'wordpress_logged_in_9f2=cookie-value; path=/; domain=www.kenya.example.test; secure',
                ]);
            }

            return Http::response('', 200);
        });

        Sanctum::actingAs($this->createUser('admin', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client/debug", [
            'target' => 'profile',
        ]);

        $response->assertOk()
            ->assertJsonPath('diagnostics.overall.status', 'fail')
            ->assertJsonPath('diagnostics.overall.failing_stage', 'host_alignment');
    }

    public function test_session_link_request_failure_returns_profile_fallback_and_logs_without_session_url(): void
    {
        $platform = Platform::factory()->create([
            'domain' => 'uganda.example.test',
            'wp_api_url' => 'https://uganda.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
            'wp_profile_permalink' => 'https://uganda.example.test/escort/nature-spot-parlour/',
        ]);
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            $baseUrl.'/clients/8517/session-link' => Http::response([
                'message' => 'Session endpoint unavailable',
            ], 502),
        ]);

        Sanctum::actingAs($this->createUser('sales', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/login-as-client", [
            'reason' => 'Fallback when Uganda session creation fails',
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'fallback_profile')
            ->assertJsonPath('session_link_generated', false)
            ->assertJsonPath('url', 'https://uganda.example.test/escort/nature-spot-parlour/')
            ->assertJsonPath('target', 'profile')
            ->assertJsonPath('fallback.available', true)
            ->assertJsonPath('fallback.url_type', 'profile')
            ->assertJsonPath('fallback.profile_url', 'https://uganda.example.test/escort/nature-spot-parlour/')
            ->assertJsonPath('fallback.login_url', 'https://uganda.example.test/wp-login.php')
            ->assertJsonPath('fallback.setup_url', 'https://uganda.example.test/wp-login.php?action=lostpassword');

        $auditLog = AuditLog::query()->where('action', 'client_login_as_client_link')->latest('id')->firstOrFail();
        $timelineEvent = TimelineEvent::query()->where('event_type', 'client_login_as_client_link_generated')->latest('id')->firstOrFail();

        $this->assertFalse((bool) data_get($auditLog->after_state, 'session_link_generated'));
        $this->assertTrue((bool) data_get($auditLog->after_state, 'fallback_used'));
        $this->assertSame('profile', data_get($auditLog->after_state, 'fallback_url_type'));
        $this->assertStringNotContainsString('crm_client_session', json_encode($auditLog->after_state));

        $this->assertFalse((bool) data_get($timelineEvent->content, 'session_link_generated'));
        $this->assertTrue((bool) data_get($timelineEvent->content, 'fallback_used'));
        $this->assertSame('profile', data_get($timelineEvent->content, 'fallback_url_type'));
        $this->assertStringNotContainsString('crm_client_session', json_encode($timelineEvent->content));
    }

    public function test_malformed_session_link_success_uses_profile_fallback(): void
    {
        $platform = Platform::factory()->create([
            'domain' => 'uganda.example.test',
            'wp_api_url' => 'https://uganda.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'wp_user_id' => 9001,
            'wp_profile_permalink' => 'https://uganda.example.test/escort/nature-spot-parlour/',
        ]);
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            $baseUrl.'/clients/8517/session-link' => Http::response([
                'expires_at' => '2026-04-03T08:45:00+00:00',
                'target' => 'profile',
            ], 200),
        ]);

        Sanctum::actingAs($this->createUser('sales', [$platform->id]));

        $this->postJson("/api/crm/clients/{$client->id}/login-as-client", [
            'reason' => 'Fallback when session payload has no URL',
        ])->assertOk()
            ->assertJsonPath('mode', 'fallback_profile')
            ->assertJsonPath('session_link_generated', false)
            ->assertJsonPath('url', 'https://uganda.example.test/escort/nature-spot-parlour/')
            ->assertJsonPath('fallback.url_type', 'profile');
    }

    public function test_marketing_out_of_market_and_unlinked_clients_cannot_generate_client_session_links(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://uganda.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $otherPlatform = Platform::factory()->create();
        $linkedClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 7001,
        ]);
        $manualClient = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 0,
            'wp_user_id' => 0,
        ]);

        Sanctum::actingAs($this->createUser('marketing', [$platform->id]));
        $this->postJson("/api/crm/clients/{$linkedClient->id}/login-as-client", [
            'reason' => 'Marketing should not generate client sessions',
        ])->assertForbidden();

        Sanctum::actingAs($this->createUser('sales', [$otherPlatform->id]));
        $this->postJson("/api/crm/clients/{$linkedClient->id}/login-as-client", [
            'reason' => 'Out of market sales should be blocked',
        ])->assertForbidden();

        Sanctum::actingAs($this->createUser('sales', [$platform->id]));
        $this->postJson("/api/crm/clients/{$manualClient->id}/login-as-client", [
            'reason' => 'Manual clients should show disabled session generation',
        ])->assertStatus(422)
            ->assertJsonPath('message', CredentialDeliveryService::LOGIN_AS_CLIENT_DISABLED_MESSAGE);
    }

    public function test_setup_link_dispatch_route_still_supports_manual_queueing(): void
    {
        $platform = Platform::factory()->create([
            'domain' => 'ghana.example.test',
            'wp_api_url' => null,
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8517,
            'email' => 'sara18@example.test',
        ]);

        Sanctum::actingAs($this->createUser('sales', [$platform->id]));

        $response = $this->postJson("/api/crm/clients/{$client->id}/credentials/dispatch", [
            'method' => 'setup_link',
            'channel' => 'email',
            'timing' => 'manual_send_later',
            'recipient_email' => 'sara18@example.test',
            'reason' => 'Regression coverage for queued credential dispatch',
        ]);

        $response->assertCreated()
            ->assertJsonPath('dispatch.status', 'deferred')
            ->assertJsonPath('dispatch.method', 'setup_link')
            ->assertJsonPath('dispatch.channel', 'email');

        $dispatch = ClientCredentialDispatch::query()->latest('id')->firstOrFail();
        $this->assertSame('Regression coverage for queued credential dispatch', data_get($dispatch->payload, 'reason'));
        $this->assertNull(data_get($dispatch->payload, 'temporary_password'));

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'action' => 'client_credential_send',
        ]);

        $this->assertDatabaseHas('timeline_events', [
            'platform_id' => $platform->id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => 'client_credentials_deferred',
        ]);
    }

    public function test_sales_can_mark_wordpress_client_online_now(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 20, 20, 30, 0, config('app.timezone')));

        try {
            $platform = Platform::factory()->create([
                'wp_api_url' => 'https://uganda.example.test/wp-json/exotic-crm-sync/v1',
                'wp_api_user' => 'crm-user',
                'wp_api_password' => 'secret',
            ]);
            $client = Client::factory()->create([
                'platform_id' => $platform->id,
                'wp_post_id' => 1392904,
                'wp_user_id' => 881122,
                'last_online_at' => now()->subDays(3)->timestamp,
                'wp_profile_permalink' => 'https://uganda.example.test/escort/mulungi-3/',
            ]);
            $baseUrl = rtrim((string) $platform->wp_api_url, '/');

            Http::fake([
                $baseUrl.'/clients/1392904/online-now' => Http::response([
                    'last_online' => now()->timestamp,
                    'online_window_minutes' => 1440,
                    'profile_url' => 'https://uganda.example.test/escort/mulungi-3/',
                ], 200),
            ]);

            Sanctum::actingAs($this->createUser('sales', [$platform->id]));

            $response = $this->postJson("/api/crm/clients/{$client->id}/online-now");

            $response->assertOk()
                ->assertJsonPath('message', 'Client marked online on WordPress.')
                ->assertJsonPath('last_online_at', now()->timestamp)
                ->assertJsonPath('online_window_minutes', 1440)
                ->assertJsonPath('profile_url', 'https://uganda.example.test/escort/mulungi-3/');

            Http::assertSent(function ($request) use ($baseUrl) {
                return $request->url() === $baseUrl.'/clients/1392904/online-now'
                    && $request['reason'] === 'CRM Online Now refresh';
            });

            $this->assertSame(now()->timestamp, (int) $client->fresh()->last_online_at);

            $this->assertDatabaseHas('audit_log', [
                'platform_id' => $platform->id,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'action' => 'client_online_now_mark',
            ]);

            $this->assertDatabaseHas('timeline_events', [
                'platform_id' => $platform->id,
                'entity_type' => 'client',
                'entity_id' => $client->id,
                'event_type' => 'client_online_now_marked',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_online_now_requires_linked_wordpress_profile(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://uganda.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 0,
            'wp_user_id' => null,
        ]);

        Http::fake();

        Sanctum::actingAs($this->createUser('sales', [$platform->id]));

        $this->postJson("/api/crm/clients/{$client->id}/online-now")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A linked WordPress profile is required to mark this client online.');

        Http::assertNothingSent();
    }

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $role === 'admin' ? [] : $assignedMarketIds,
        ]);

        if (! empty($assignedMarketIds)) {
            $user->platforms()->syncWithoutDetaching($assignedMarketIds);
        }

        return $user;
    }
}
