<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientCredentialDispatch;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\CredentialDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $role === 'admin' ? [] : $assignedMarketIds,
        ]);

        if (!empty($assignedMarketIds)) {
            $user->platforms()->syncWithoutDetaching($assignedMarketIds);
        }

        return $user;
    }
}
