<?php

namespace Tests\Feature\Collections;

use App\Console\Kernel;
use App\Services\Collections\CollectionOutboxWorker;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

class CollectionWorkerLifecycleTest extends CollectionTestCase
{
    public function test_expired_worker_budget_leaves_durable_work_unclaimed(): void
    {
        $id = DB::table('collection_outbox')->insertGetId(['kind' => 'unknown', 'subject_id' => 'fixture', 'dedupe_key' => 'budget-fixture', 'available_at' => now()]);
        $worker = app(CollectionOutboxWorker::class);
        $this->assertSame(['done' => 0, 'held' => 0], $worker->run(100, hrtime(true) - 1));
        $this->assertDatabaseHas('collection_outbox', ['id' => $id, 'attempts' => 0, 'completed_at' => null]);
        $this->assertSame(['done' => 0, 'held' => 1], $worker->run(100, hrtime(true) + 10_000_000_000));
        $this->assertDatabaseHas('collection_outbox', ['id' => $id, 'attempts' => 1, 'last_error_code' => 'unknown_outbox_kind']);
    }

    public function test_bounded_loop_stops_without_needing_an_external_signal(): void
    {
        $worker = new class extends CollectionOutboxWorker
        {
            public int $calls = 0;

            public function run(int $limit = 100, ?int $deadline = null): array
            {
                $this->calls++;

                return ['done' => 0, 'held' => 0];
            }
        };
        $this->app->instance(CollectionOutboxWorker::class, $worker);
        $this->artisan('collections:work', ['--loop' => true, '--poll' => 1, '--max-runtime' => 1])->assertExitCode(0);
        $this->assertSame(1, $worker->calls);
    }

    public function test_scheduler_registers_only_enabled_bounded_background_capture(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $method = new \ReflectionMethod($kernel, 'schedule');
        $find = fn ($schedule) => collect($schedule->events())->first(fn ($event) => str_contains($event->command ?? '', 'collections:work'));
        config(['collection.capture_enabled' => false]);
        $off = new Schedule;
        $method->invoke($kernel, $off);
        $this->assertNull($find($off));
        config(['collection.capture_enabled' => true]);
        $on = new Schedule;
        $method->invoke($kernel, $on);
        $event = $find($on);
        $this->assertNotNull($event);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame(5, $event->expiresAt);
        $this->assertStringContainsString('--max-runtime=45', $event->command);
    }
}
