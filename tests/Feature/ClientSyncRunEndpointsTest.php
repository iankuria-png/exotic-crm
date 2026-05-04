<?php

namespace Tests\Feature;

use App\Jobs\RunClientSyncJob;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientSyncRunEndpointsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_queue_background_client_sync_from_settings(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake();

        $platform = $this->createPlatform();
        $admin = $this->createUser('admin');

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/settings/integrations/platforms/{$platform->id}/sync", [
            'scope' => 'clients',
            'mode' => 'full',
            'dry_run' => false,
            'per_page' => 100,
            'reason' => 'Queue client sync from test',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('run.mode', 'reconcile')
            ->assertJsonPath('run.status', 'queued');

        $this->assertDatabaseHas('client_sync_runs', [
            'platform_id' => $platform->id,
            'origin' => 'manual',
            'mode' => 'reconcile',
            'status' => 'queued',
        ]);

        Queue::assertPushed(RunClientSyncJob::class, 1);

        $latest = $this->getJson("/api/crm/settings/integrations/platforms/{$platform->id}/sync/latest");
        $latest->assertOk()
            ->assertJsonPath('run.mode', 'reconcile')
            ->assertJsonPath('run.status', 'queued');
    }

    public function test_refresh_capabilities_marks_legacy_markets_when_v2_meta_route_is_missing(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createUser('admin');

        Http::fake([
            rtrim($platform->wp_api_url, '/') . '/sync/meta*' => Http::response(['message' => 'Not found'], 404),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/crm/settings/integrations/platforms/{$platform->id}/capabilities/refresh");

        $response->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('capability.status', 'legacy_not_found')
            ->assertJsonPath('platform.client_sync.capability_status', 'legacy_not_found')
            ->assertJsonPath('platform.client_sync.protocol', 'v1');

        $this->assertDatabaseHas('platforms', [
            'id' => $platform->id,
            'client_sync_capability_status' => 'legacy_not_found',
            'client_sync_protocol' => 'v1',
        ]);
    }

    private function createPlatform(array $attributes = []): Platform
    {
        return Platform::query()->create(array_merge([
            'name' => 'Kenya',
            'domain' => 'kenya.example.test',
            'country' => 'Kenya',
            'is_active' => true,
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'wp-api-user',
            'wp_api_password' => 'wp-api-password',
            'db_host' => '127.0.0.1',
            'db_name' => 'wp_kenya',
            'db_user' => 'wp_user',
            'db_pass' => 'secret',
            'db_prefix' => 'wp_',
            'phone_prefix' => '254',
            'timezone' => 'Africa/Nairobi',
            'currency_code' => 'KES',
        ], $attributes));
    }

    private function createUser(string $role = 'admin', array $assignedMarketIds = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
            'email' => sprintf('%s-%s@example.test', $role, uniqid()),
        ]);
    }
}
