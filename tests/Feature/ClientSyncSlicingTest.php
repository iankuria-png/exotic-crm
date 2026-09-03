<?php

namespace Tests\Feature;

use App\Jobs\RunClientSyncJob;
use App\Models\Client;
use App\Models\ClientSyncRun;
use App\Models\Platform;
use App\Services\ClientSyncService;
use App\Support\SyncSliceBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A sync run is executed as a series of bounded slices so that no market can
 * occupy a queue worker indefinitely. These tests pin the two properties that
 * matter: a slice stops when its budget is spent, and the next slice resumes
 * from exactly where it stopped without losing or re-applying work.
 */
class ClientSyncSlicingTest extends TestCase
{
    use RefreshDatabase;

    private function platform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Slice Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://slice.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function makeRun(Platform $platform, string $mode = 'delta'): ClientSyncRun
    {
        return ClientSyncRun::query()->create([
            'platform_id' => $platform->id,
            'origin' => 'scheduler',
            'mode' => $mode,
            'status' => ClientSyncRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);
    }

    /**
     * Two feed pages, a one-page budget: the first slice must stop after page
     * one, report itself incomplete, and leave the cursor pointing at page two.
     */
    public function test_slice_stops_on_budget_and_persists_its_cursor(): void
    {
        $platform = $this->platform();
        $run = $this->makeRun($platform);

        Http::fake([
            'https://slice.test/wp-json/exotic-crm-sync/v1/sync/meta*' => Http::response([
                'supports_cursor_sync' => true,
                'sync_contract_version' => '2',
            ], 200),
            'https://slice.test/wp-json/exotic-crm-sync/v1/clients/sync*' => Http::sequence()
                ->push([
                    'data' => [[
                        'wp_post_id' => 501,
                        'wp_user_id' => 601,
                        'name' => 'Page One Client',
                        'phone' => '0711000501',
                        'post_status' => 'publish',
                    ]],
                    'run_upper_bound_modified_at' => '2026-09-03 10:00:00',
                    'has_more' => true,
                    'next_cursor_modified_at' => '2026-09-03 09:30:00',
                    'next_cursor_post_id' => 501,
                ], 200)
                ->push([
                    'data' => [[
                        'wp_post_id' => 502,
                        'wp_user_id' => 602,
                        'name' => 'Page Two Client',
                        'phone' => '0711000502',
                        'post_status' => 'publish',
                    ]],
                    'run_upper_bound_modified_at' => '2026-09-03 10:00:00',
                    'has_more' => false,
                ], 200),
        ]);

        $result = (new ClientSyncService($platform))->runBulkSync(
            $run,
            100,
            new SyncSliceBudget(3600, 1)
        );

        $this->assertFalse($result['complete'], 'A budget-capped slice must report itself incomplete.');
        $this->assertSame(1, (int) $result['processed']);

        $run->refresh();
        $this->assertSame(ClientSyncRun::PHASE_CLIENTS, $run->phase);
        $this->assertSame(501, (int) $run->cursor_post_id);
        $this->assertNotNull($run->run_upper_bound_modified_at);

        // The checkpoint must NOT advance while pages are outstanding, or the
        // unread remainder would be skipped on the next delta.
        $this->assertNull($platform->fresh()->client_sync_checkpoint_at);

        $this->assertDatabaseHas('clients', ['wp_post_id' => 501]);
        $this->assertDatabaseMissing('clients', ['wp_post_id' => 502]);
    }

    /**
     * Feeding the same run back in must continue from the stored cursor and
     * finish the job, advancing the checkpoint exactly once.
     */
    public function test_next_slice_resumes_from_the_stored_cursor_and_completes(): void
    {
        $platform = $this->platform();
        $run = $this->makeRun($platform);

        $run->forceFill([
            'phase' => ClientSyncRun::PHASE_CLIENTS,
            'cursor_modified_at' => '2026-09-03 09:30:00',
            'cursor_post_id' => 501,
            'run_upper_bound_modified_at' => '2026-09-03 10:00:00',
        ])->save();

        Http::fake([
            'https://slice.test/wp-json/exotic-crm-sync/v1/sync/meta*' => Http::response([
                'supports_cursor_sync' => true,
                'sync_contract_version' => '2',
            ], 200),
            'https://slice.test/wp-json/exotic-crm-sync/v1/clients/sync*' => Http::response([
                'data' => [[
                    'wp_post_id' => 502,
                    'wp_user_id' => 602,
                    'name' => 'Page Two Client',
                    'phone' => '0711000502',
                    'post_status' => 'publish',
                ]],
                'run_upper_bound_modified_at' => '2026-09-03 10:00:00',
                'has_more' => false,
            ], 200),
        ]);

        $result = (new ClientSyncService($platform))->runBulkSync(
            $run,
            100,
            new SyncSliceBudget(3600, 25)
        );

        $this->assertTrue($result['complete']);
        $this->assertDatabaseHas('clients', ['wp_post_id' => 502]);
        $this->assertNotNull($platform->fresh()->client_sync_checkpoint_at);

        // The resumed request must carry the persisted cursor, and must pin the
        // upper bound from the first slice so mid-run edits cannot slip behind.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/clients/sync')) {
                return false;
            }

            return str_contains($request->url(), 'cursor_post_id=501')
                && str_contains($request->url(), 'modified_before=');
        });
    }

    public function test_job_requeues_itself_when_a_slice_leaves_work_behind(): void
    {
        Queue::fake();

        $platform = $this->platform();
        $run = $this->makeRun($platform);

        Http::fake([
            'https://slice.test/wp-json/exotic-crm-sync/v1/sync/meta*' => Http::response([
                'supports_cursor_sync' => true,
                'sync_contract_version' => '2',
            ], 200),
            'https://slice.test/wp-json/exotic-crm-sync/v1/clients/sync*' => Http::response([
                'data' => [[
                    'wp_post_id' => 701,
                    'wp_user_id' => 801,
                    'name' => 'Backlog Client',
                    'phone' => '0711000701',
                    'post_status' => 'publish',
                ]],
                'run_upper_bound_modified_at' => '2026-09-03 10:00:00',
                'has_more' => true,
                'next_cursor_modified_at' => '2026-09-03 09:30:00',
                'next_cursor_post_id' => 701,
            ], 200),
        ]);

        config()->set('services.client_sync.slice_max_pages', 1);

        (new RunClientSyncJob((int) $run->id, 100))->handle(app(\App\Services\ClientSyncRunService::class));

        Queue::assertPushed(RunClientSyncJob::class, fn (RunClientSyncJob $job) => $job->runId === (int) $run->id);

        // The run stays open across slices rather than being marked done early.
        $this->assertSame(ClientSyncRun::STATUS_RUNNING, $run->fresh()->status);
    }

    public function test_run_is_failed_once_it_exceeds_the_slice_ceiling(): void
    {
        Queue::fake();

        $platform = $this->platform();
        $run = $this->makeRun($platform);
        $run->forceFill(['slices' => 400])->save();

        (new RunClientSyncJob((int) $run->id, 100))->handle(app(\App\Services\ClientSyncRunService::class));

        $this->assertSame(ClientSyncRun::STATUS_FAILED, $run->fresh()->status);
        Queue::assertNotPushed(RunClientSyncJob::class);
    }
}
