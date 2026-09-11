<?php

namespace Tests\Feature;

use App\Jobs\Concerns\Sheddable;
use App\Services\Ops\LoadShedder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ShedProbeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use Sheddable;

    /** The fatal case: a single shed used to exhaust this outright. */
    public int $tries = 1;

    public function shedCapability(): string
    {
        return 'probe_capability';
    }

    public function handle(): void
    {
        if ($this->shedIfDegraded()) {
            return;
        }
    }
}

/**
 * Load shedding must defer work, not destroy it.
 *
 * release() re-queues with the attempt counter the worker already spent, so a
 * shed used to consume one of the job's tries. Three jobs using this trait run
 * with tries=1 and are dispatched by a person clicking a button in the CRM, so
 * the first shed would have failed them outright — silently, the first time
 * shedding was ever switched on.
 */
class SheddableJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs the sync queue, which executes on dispatch and never
        // touches the jobs table. This trait's whole behaviour is about what
        // the queue record looks like after a shed, so it needs a real one.
        config([
            'queue.default' => 'database',
            'queue.connections.database.driver' => 'database',
            'queue.connections.database.table' => 'jobs',
            'queue.connections.database.queue' => 'default',
            'queue.connections.database.retry_after' => 900,
        ]);
    }

    private function shedEverything(): void
    {
        $this->app->bind(LoadShedder::class, fn () => new class extends LoadShedder
        {
            public function __construct()
            {
            }

            public function allows(string $capability): bool
            {
                return false;
            }
        });
    }

    private function allowEverything(): void
    {
        $this->app->bind(LoadShedder::class, fn () => new class extends LoadShedder
        {
            public function __construct()
            {
            }

            public function allows(string $capability): bool
            {
                return true;
            }
        });
    }

    public function test_a_shed_requeues_the_job_instead_of_spending_an_attempt(): void
    {
        $this->shedEverything();

        ShedProbeJob::dispatch();
        $this->assertSame(1, DB::table('jobs')->count());

        $before = DB::table('jobs')->first();
        $this->assertSame(0, (int) $before->attempts);

        // Run the queued job exactly as a worker would.
        $this->artisan('queue:work', [
            '--once' => true,
            '--queue' => $before->queue,
        ])->assertSuccessful();

        // Still exactly one job queued, and it has NOT accumulated an attempt.
        $this->assertSame(1, DB::table('jobs')->count(), 'the shed must leave the work queued');
        $after = DB::table('jobs')->first();
        $this->assertSame(0, (int) $after->attempts, 'a shed must not spend a retry attempt');
        $this->assertNotSame(
            (int) $before->id,
            (int) $after->id,
            'the deferred job is a fresh record, which is what resets the counter'
        );
    }

    public function test_nothing_is_ever_marked_failed_by_a_shed(): void
    {
        $this->shedEverything();

        ShedProbeJob::dispatch();
        $queue = DB::table('jobs')->value('queue');

        // Several sheds in a row on a tries=1 job — the case that used to die.
        for ($i = 0; $i < 3; $i++) {
            $this->travel(310)->seconds();
            $this->artisan('queue:work', ['--once' => true, '--queue' => $queue])->assertSuccessful();
        }

        $this->assertSame(0, DB::table('failed_jobs')->count(), 'a shed must never fail a job');
        $this->assertSame(1, DB::table('jobs')->count(), 'the work must still be queued');
    }

    public function test_the_shed_count_climbs_so_repeated_sheds_are_bounded(): void
    {
        $this->shedEverything();

        ShedProbeJob::dispatch();
        $queue = DB::table('jobs')->value('queue');

        foreach ([1, 2, 3] as $expected) {
            // Each deferral lands 300s in the future, so the next worker pass
            // only sees it once that delay has elapsed.
            $this->travel(310)->seconds();
            $this->artisan('queue:work', ['--once' => true, '--queue' => $queue])->assertSuccessful();

            $payload = json_decode(DB::table('jobs')->value('payload'), true);
            $command = unserialize($payload['data']['command']);

            $this->assertSame(
                $expected,
                $command->shedCount,
                'each shed must be counted so MAX_SHEDS can end the cycle'
            );
        }
    }

    public function test_a_job_that_is_not_shed_runs_normally(): void
    {
        $this->allowEverything();

        Queue::fake();
        ShedProbeJob::dispatch();
        Queue::assertPushed(ShedProbeJob::class);
    }
}
