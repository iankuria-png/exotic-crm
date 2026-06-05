<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientSyncRun;
use App\Models\Platform;
use App\Models\User;
use App\Services\ClientSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClientProfileUrlSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_one_persists_permalink_and_slug_without_changing_short_wp_profile_url(): void
    {
        $platform = $this->createPlatform();

        Http::fake([
            'https://kenya.example.test/wp-json/exotic-crm-sync/v1/clients/10026' => Http::response(
                $this->wpClientPayload(10026, [
                    'wp_profile_permalink' => 'https://kenya.example.test/escort/faithvideossquirtingnudes/',
                    'wp_profile_slug' => 'faithvideossquirtingnudes',
                ]),
                200
            ),
        ]);

        $client = (new ClientSyncService($platform))->syncOne(10026);

        $this->assertSame('https://kenya.example.test/?p=10026', $client->wp_profile_url);
        $this->assertSame('https://kenya.example.test/escort/faithvideossquirtingnudes/', $client->wp_profile_permalink);
        $this->assertSame('faithvideossquirtingnudes', $client->wp_profile_slug);
    }

    public function test_legacy_full_sync_persists_permalink_and_slug_without_changing_short_wp_profile_url(): void
    {
        $platform = $this->createPlatform();

        Http::fake([
            'https://kenya.example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [
                    $this->wpClientPayload(10026, [
                        'wp_profile_permalink' => 'https://kenya.example.test/escort/faithvideossquirtingnudes/',
                        'wp_profile_slug' => 'faithvideossquirtingnudes',
                    ]),
                ],
                'pages' => 1,
            ], 200),
        ]);

        $result = (new ClientSyncService($platform))->fullSync();
        $client = Client::query()->where('platform_id', $platform->id)->where('wp_post_id', 10026)->firstOrFail();

        $this->assertSame(1, $result['created']);
        $this->assertSame('https://kenya.example.test/?p=10026', $client->wp_profile_url);
        $this->assertSame('https://kenya.example.test/escort/faithvideossquirtingnudes/', $client->wp_profile_permalink);
        $this->assertSame('faithvideossquirtingnudes', $client->wp_profile_slug);
    }

    public function test_v2_cursor_sync_persists_permalink_and_slug(): void
    {
        $platform = $this->createPlatform([
            'client_sync_capability_checked_at' => now(),
            'client_sync_capability_status' => 'v2',
            'client_sync_protocol' => 'v2',
            'client_sync_contract_version' => '2',
        ]);
        $run = ClientSyncRun::query()->create([
            'platform_id' => $platform->id,
            'origin' => 'manual',
            'mode' => 'delta',
            'status' => ClientSyncRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        Http::fake([
            'https://kenya.example.test/wp-json/exotic-crm-sync/v1/clients/sync*' => Http::response([
                'contract_version' => '2',
                'run_upper_bound_modified_at' => '2026-05-06 09:00:00',
                'data' => [
                    $this->wpClientPayload(10027, [
                        'wp_profile_permalink' => 'https://kenya.example.test/escort/another-profile/',
                        'wp_profile_slug' => 'another-profile',
                        'modified_at' => '2026-05-06 08:30:00',
                    ]),
                ],
                'count' => 1,
                'has_more' => false,
                'next_cursor_modified_at' => '2026-05-06 08:30:00',
                'next_cursor_post_id' => 10027,
            ], 200),
        ]);

        $result = (new ClientSyncService($platform))->runBulkSync($run, 50);
        $client = Client::query()->where('platform_id', $platform->id)->where('wp_post_id', 10027)->firstOrFail();

        $this->assertSame(1, $result['created']);
        $this->assertSame('https://kenya.example.test/escort/another-profile/', $client->wp_profile_permalink);
        $this->assertSame('another-profile', $client->wp_profile_slug);
    }

    public function test_v2_full_reconcile_deletes_source_backed_clients_not_returned_by_wordpress(): void
    {
        $user = User::factory()->create();
        $platform = $this->createPlatform([
            'client_sync_capability_checked_at' => now(),
            'client_sync_capability_status' => 'v2',
            'client_sync_protocol' => 'v2',
            'client_sync_contract_version' => '2',
        ]);

        $staleClient = Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 10020,
            'wp_user_id' => 40020,
            'client_type' => 'escort',
            'name' => 'Deleted Upstream',
            'phone_normalized' => '254711000020',
            'email' => 'deleted@example.test',
            'profile_status' => 'publish',
            'last_seen_in_reconcile_at' => now()->subDays(2),
        ]);

        $crmOnlyClient = Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 0,
            'client_type' => 'escort',
            'name' => 'Manual CRM Client',
            'phone_normalized' => '254711000021',
            'email' => 'manual@example.test',
            'profile_status' => 'private',
            'last_seen_in_reconcile_at' => null,
        ]);

        $run = ClientSyncRun::query()->create([
            'platform_id' => $platform->id,
            'initiated_by' => $user->id,
            'origin' => 'manual',
            'mode' => 'reconcile',
            'status' => ClientSyncRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        Http::fake([
            'https://kenya.example.test/wp-json/exotic-crm-sync/v1/clients/sync*' => Http::response([
                'contract_version' => '2',
                'run_upper_bound_modified_at' => '2026-05-06 09:00:00',
                'data' => [
                    $this->wpClientPayload(10027, [
                        'name' => 'Still Present',
                        'modified_at' => '2026-05-06 08:30:00',
                    ]),
                ],
                'count' => 1,
                'has_more' => false,
                'next_cursor_modified_at' => '2026-05-06 08:30:00',
                'next_cursor_post_id' => 10027,
            ], 200),
            'https://kenya.example.test/wp-json/exotic-crm-sync/v1/clients/tombstones*' => Http::response([
                'contract_version' => '2',
                'removed_upper_bound' => '2026-05-06 09:00:00',
                'data' => [],
                'count' => 0,
                'has_more' => false,
                'next_cursor_removed_at' => null,
                'next_cursor_post_id' => null,
            ], 200),
        ]);

        $result = (new ClientSyncService($platform))->runBulkSync($run, 50);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['pruned']);
        $this->assertDatabaseMissing('clients', ['id' => $staleClient->id]);
        $this->assertDatabaseHas('clients', ['id' => $crmOnlyClient->id]);
        $this->assertDatabaseHas('clients', [
            'platform_id' => $platform->id,
            'wp_post_id' => 10027,
            'source_presence_status' => 'present',
        ]);
        $this->assertDatabaseMissing('client_sync_exclusions', [
            'platform_id' => $platform->id,
            'wp_post_id' => 10020,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'actor_id' => $user->id,
            'action' => 'client_auto_purge',
            'entity_type' => 'client',
            'entity_id' => $staleClient->id,
        ]);
    }

    public function test_legacy_reconcile_fallback_runs_full_sync_and_prunes_stale_clients(): void
    {
        $user = User::factory()->create();
        $platform = $this->createPlatform();

        $staleClient = Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 10030,
            'wp_user_id' => 40030,
            'client_type' => 'escort',
            'name' => 'Legacy Deleted',
            'phone_normalized' => '254711000030',
            'email' => 'legacy-deleted@example.test',
            'profile_status' => 'publish',
            'last_seen_in_reconcile_at' => now()->subDays(2),
        ]);

        $run = ClientSyncRun::query()->create([
            'platform_id' => $platform->id,
            'initiated_by' => $user->id,
            'origin' => 'manual',
            'mode' => 'reconcile',
            'status' => ClientSyncRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        Http::fake([
            'https://kenya.example.test/wp-json/exotic-crm-sync/v1/sync/meta' => Http::response([], 404),
            'https://kenya.example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [
                    $this->wpClientPayload(10031, [
                        'name' => 'Legacy Present',
                        'modified_at' => '2026-05-06 08:30:00',
                    ]),
                ],
                'pages' => 1,
            ], 200),
        ]);

        $result = (new ClientSyncService($platform))->runBulkSync($run, 50);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['pruned']);
        $this->assertDatabaseMissing('clients', ['id' => $staleClient->id]);
        $this->assertDatabaseHas('clients', [
            'platform_id' => $platform->id,
            'wp_post_id' => 10031,
            'source_presence_status' => 'present',
        ]);
        $this->assertSame('full_legacy', $run->fresh()->mode);
    }

    private function createPlatform(array $attributes = []): Platform
    {
        return Platform::factory()->create(array_merge([
            'name' => 'Kenya',
            'domain' => 'kenya.example.test',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'timezone' => 'Africa/Nairobi',
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ], $attributes));
    }

    private function wpClientPayload(int $postId, array $overrides = []): array
    {
        return array_merge([
            'wp_post_id' => $postId,
            'wp_user_id' => $postId + 30000,
            'name' => 'Faith Videos',
            'phone' => '0712345678',
            'email' => 'faith@example.test',
            'city' => 'Nairobi',
            'post_status' => 'publish',
            'premium' => true,
            'premium_expire' => null,
            'featured' => false,
            'featured_expire' => null,
            'escort_expire' => now()->addDays(14)->timestamp,
            'verified' => false,
            'needs_payment' => false,
            'notactive' => false,
            'main_image_url' => '',
            'modified_at' => '2026-05-06 08:00:00',
        ], $overrides);
    }
}
