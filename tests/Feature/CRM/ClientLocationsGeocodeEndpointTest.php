<?php

namespace Tests\Feature\CRM;

use App\Jobs\GeocodeMarketCitiesJob;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientLocationsGeocodeEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_geocode_job_for_accessible_market(): void
    {
        Queue::fake();
        config()->set('queue.default', 'database');

        $platform = $this->makePlatform();
        $user = $this->makeUser('sales', [$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/clients/locations/geocode', [
            'platform_id' => $platform->id,
        ]);

        $response->assertOk()->assertJson(['status' => 'queued']);

        Queue::assertPushed(
            GeocodeMarketCitiesJob::class,
            fn (GeocodeMarketCitiesJob $job) => $job->platformId === (int) $platform->id
        );
    }

    public function test_blocks_market_the_user_cannot_access(): void
    {
        Queue::fake();
        config()->set('queue.default', 'database');

        $platform = $this->makePlatform();
        $otherPlatform = $this->makePlatform('Tanzania');
        $user = $this->makeUser('sales', [$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/clients/locations/geocode', [
            'platform_id' => $otherPlatform->id,
        ]);

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_marketing_role_cannot_trigger_geocoding(): void
    {
        Queue::fake();
        config()->set('queue.default', 'database');

        $platform = $this->makePlatform();
        $user = $this->makeUser('marketing', [$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/clients/locations/geocode', [
            'platform_id' => $platform->id,
        ]);

        $response->assertForbidden();
        Queue::assertNothingPushed();
    }

    public function test_returns_503_when_queue_is_sync(): void
    {
        Queue::fake();
        config()->set('queue.default', 'sync');

        $platform = $this->makePlatform();
        $user = $this->makeUser('sales', [$platform->id]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/clients/locations/geocode', [
            'platform_id' => $platform->id,
        ]);

        $response->assertStatus(503);
        Queue::assertNothingPushed();
    }

    private function makePlatform(string $country = 'Kenya'): Platform
    {
        return Platform::factory()->create([
            'country' => $country,
            'wp_api_url' => 'https://' . strtolower($country) . '.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function makeUser(string $role, array $assignedMarketIds): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
        ]);
    }
}
