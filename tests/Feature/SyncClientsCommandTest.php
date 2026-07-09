<?php

namespace Tests\Feature;

use App\Models\Platform;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncClientsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_continues_syncing_other_markets_after_one_market_fails(): void
    {
        $platformA = Platform::factory()->create([
            'name' => 'Kenya',
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $platformB = Platform::factory()->create([
            'name' => 'Tanzania',
            'wp_api_url' => 'https://tanzania.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $platformC = Platform::factory()->create([
            'name' => 'Ghana',
            'wp_api_url' => 'https://ghana.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        Http::fake([
            'https://kenya.example.test/wp-json/exotic-crm-sync/v1/sync/meta' => Http::response([], 404),
            'https://kenya.example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [[
                    'wp_post_id' => 9101,
                    'wp_user_id' => 8101,
                    'name' => 'Kenya Client',
                    'phone' => '0711000101',
                    'email' => 'kenya@example.test',
                    'city' => 'Nairobi',
                    'post_status' => 'publish',
                    'modified_at' => '2026-04-03 12:00:00',
                ]],
                'pages' => 1,
            ], 200),
            'https://tanzania.example.test/wp-json/exotic-crm-sync/v1/sync/meta' => Http::response([], 404),
            'https://tanzania.example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'message' => 'Upstream outage',
            ], 500),
            'https://ghana.example.test/wp-json/exotic-crm-sync/v1/sync/meta' => Http::response([], 404),
            'https://ghana.example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [[
                    'wp_post_id' => 9301,
                    'wp_user_id' => 8301,
                    'name' => 'Ghana Client',
                    'phone' => '0711000301',
                    'email' => 'ghana@example.test',
                    'city' => 'Accra',
                    'post_status' => 'publish',
                    'modified_at' => '2026-04-03 12:05:00',
                ]],
                'pages' => 1,
            ], 200),
        ]);

        $this->artisan('crm:sync-clients')
            ->assertExitCode(1);

        $this->assertDatabaseHas('clients', [
            'platform_id' => $platformA->id,
            'wp_post_id' => 9101,
        ]);
        $this->assertDatabaseHas('clients', [
            'platform_id' => $platformC->id,
            'wp_post_id' => 9301,
        ]);
        $this->assertDatabaseCount('clients', 2);

        $platformA->refresh();
        $platformB->refresh();
        $platformC->refresh();

        $this->assertSame('success', $platformA->sync_last_status);
        $this->assertSame('scheduler', data_get($platformA->sync_last_result, 'trigger'));
        $this->assertSame('delta', data_get($platformA->sync_last_result, 'mode'));

        $this->assertSame('error', $platformB->sync_last_status);
        $this->assertSame('scheduler', data_get($platformB->sync_last_result, 'trigger'));
        $this->assertSame('delta', data_get($platformB->sync_last_result, 'mode'));
        $this->assertNotEmpty($platformB->sync_last_error);

        $this->assertSame('success', $platformC->sync_last_status);
        $this->assertSame('scheduler', data_get($platformC->sync_last_result, 'trigger'));

        Http::assertSentCount(8);
    }

    public function test_command_skips_markets_without_complete_wordpress_credentials(): void
    {
        Platform::factory()->create([
            'name' => 'Incomplete Market',
            'wp_api_url' => 'https://incomplete.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => null,
        ]);

        $platform = Platform::factory()->create([
            'name' => 'Complete Market',
            'wp_api_url' => 'https://complete.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        Http::fake([
            'https://complete.example.test/wp-json/exotic-crm-sync/v1/sync/meta' => Http::response([], 404),
            'https://complete.example.test/wp-json/exotic-crm-sync/v1/clients*' => Http::response([
                'data' => [[
                    'wp_post_id' => 9401,
                    'wp_user_id' => 8401,
                    'name' => 'Complete Client',
                    'phone' => '0711000401',
                    'email' => 'complete@example.test',
                    'city' => 'Kampala',
                    'post_status' => 'publish',
                    'modified_at' => '2026-04-03 12:10:00',
                ]],
                'pages' => 1,
            ], 200),
        ]);

        $this->artisan('crm:sync-clients')
            ->assertExitCode(0);

        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('clients', [
            'platform_id' => $platform->id,
            'wp_post_id' => 9401,
        ]);

        Http::assertSentCount(2);
    }

    public function test_command_limits_and_rotates_platforms_for_scheduled_delta_sync(): void
    {
        Queue::fake();
        Carbon::setTestNow(Carbon::create(1970, 1, 1, 0, 30, 0, 'UTC'));

        $platforms = collect(['Kenya', 'Uganda', 'Ghana', 'Nigeria', 'Tanzania'])
            ->map(fn (string $name) => Platform::factory()->create([
                'name' => $name,
                'wp_api_url' => sprintf('https://%s.example.test/wp-json/exotic-crm-sync/v1', strtolower($name)),
                'wp_api_user' => 'crm-user',
                'wp_api_password' => 'secret',
            ]))
            ->values();

        $this->artisan('crm:sync-clients --max-platforms=3 --rotate --per-page=50 --stagger-seconds=120')
            ->assertExitCode(0);

        $expectedPlatformIds = [
            $platforms[3]->id,
            $platforms[4]->id,
            $platforms[0]->id,
        ];

        Queue::assertPushed(\App\Jobs\RunClientSyncJob::class, 3);
        Queue::assertPushed(\App\Jobs\RunClientSyncJob::class, function ($job) use ($expectedPlatformIds) {
            $run = \App\Models\ClientSyncRun::find($job->runId);

            return $run
                && in_array((int) $run->platform_id, $expectedPlatformIds, true)
                && $job->perPage === 50
                && $job->queue === 'sync-clients';
        });

        $this->assertEqualsCanonicalizing(
            $expectedPlatformIds,
            \App\Models\ClientSyncRun::query()->pluck('platform_id')->map(fn ($id) => (int) $id)->all()
        );
    }

    public function test_manual_platform_sync_ignores_platform_window_limits(): void
    {
        Queue::fake();

        $platformA = Platform::factory()->create([
            'name' => 'Kenya',
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        Platform::factory()->create([
            'name' => 'Uganda',
            'wp_api_url' => 'https://uganda.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $this->artisan(sprintf('crm:sync-clients --platform=%d --max-platforms=0 --per-page=75', $platformA->id))
            ->assertExitCode(0);

        Queue::assertPushed(\App\Jobs\RunClientSyncJob::class, 1);
        Queue::assertPushed(\App\Jobs\RunClientSyncJob::class, function ($job) use ($platformA) {
            $run = \App\Models\ClientSyncRun::find($job->runId);

            return $run
                && (int) $run->platform_id === (int) $platformA->id
                && $job->perPage === 75;
        });
    }
}
