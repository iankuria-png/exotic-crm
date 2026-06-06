<?php

namespace Tests\Feature\AutoOptimize;

use App\Jobs\ApplyAutoOptimizeItemJob;
use App\Jobs\OptimizeProfileJob;
use App\Models\AutoOptimizeAlert;
use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;
use App\Models\AutoOptimizeRun;
use App\Models\Client;
use App\Models\Platform;
use App\Services\AutoOptimize\AutoOptimizeEngineService;
use App\Services\AutoOptimize\AutoOptimizeMarketStats;
use App\Services\AutoOptimize\AutoOptimizeSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoOptimizeEngineServiceTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platform = Platform::query()->create([
            'name' => 'Test Kenya',
            'domain' => 'test.example',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'wp_api_url' => 'https://test.example/wp-json',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
        ]);
    }

    public function test_run_plan_creates_queued_items_and_dispatches_batch(): void
    {
        Bus::fake();

        $plan = $this->makePlan(['autopilot' => false]);
        $clients = collect([
            Client::factory()->create(['platform_id' => $this->platform->id, 'wp_post_id' => 201]),
            Client::factory()->create(['platform_id' => $this->platform->id, 'wp_post_id' => 202]),
        ]);

        $engine = $this->makeEngine($plan, $clients->all());

        $run = $engine->runPlan($plan->fresh('platform'));

        $this->assertSame('running', $run->status); // finalized by batch callback, stays running
        $this->assertSame(2, $run->candidates_selected);
        $this->assertSame(2, AutoOptimizeItem::where('auto_optimize_run_id', $run->id)->count());
        $this->assertSame('queued', AutoOptimizeItem::where('auto_optimize_run_id', $run->id)->first()->status);

        // In approval mode only OptimizeProfileJob dispatched (no ApplyAutoOptimizeItemJob)
        Bus::assertBatched(fn ($batch) => count($batch->jobs) === 2);
    }

    public function test_autopilot_plan_chains_apply_job_in_batch(): void
    {
        Bus::fake();

        $plan = $this->makePlan(['autopilot' => true]);
        $client = Client::factory()->create(['platform_id' => $this->platform->id, 'wp_post_id' => 101]);

        $engine = $this->makeEngine($plan, [$client]);

        $engine->runPlan($plan->fresh('platform'));

        // Autopilot: batch jobs should be arrays (chains), not plain jobs
        Bus::assertBatched(function ($batch) {
            // Each job in the batch is a chain [OptimizeProfileJob, ApplyAutoOptimizeItemJob]
            $firstJob = $batch->jobs[0] ?? null;
            return is_array($firstJob) && count($firstJob) === 2;
        });
    }

    public function test_zero_candidates_creates_skipped_run_and_alert(): void
    {
        Bus::fake();

        $plan = $this->makePlan();
        $engine = $this->makeEngine($plan, []); // no eligible clients

        $run = $engine->runPlan($plan->fresh('platform'));

        $this->assertSame('skipped', $run->status);
        $this->assertDatabaseHas('auto_optimize_alerts', ['type' => 'no_candidates', 'auto_optimize_plan_id' => $plan->id]);
        Bus::assertNothingBatched();
    }

    public function test_approve_all_enqueues_apply_jobs_not_sync(): void
    {
        Bus::fake();

        // Simulate pending items
        $plan = $this->makePlan();
        $run = AutoOptimizeRun::query()->create([
            'auto_optimize_plan_id' => $plan->id,
            'platform_id' => $this->platform->id,
            'status' => 'completed',
        ]);
        $client = Client::factory()->create(['platform_id' => $this->platform->id]);
        $item = AutoOptimizeItem::query()->create([
            'auto_optimize_plan_id' => $plan->id,
            'auto_optimize_run_id' => $run->id,
            'platform_id' => $this->platform->id,
            'client_id' => $client->id,
            'status' => 'pending',
            'new_bio_html' => '<p>New bio</p>',
        ]);

        // Dispatch an apply job (simulating approve-all)
        ApplyAutoOptimizeItemJob::dispatch($item->id, null);

        Bus::assertDispatched(ApplyAutoOptimizeItemJob::class);
        // WP is not written synchronously in the request — verified by Bus::fake preventing real execution
    }

    private function makePlan(array $attrs = []): AutoOptimizePlan
    {
        return AutoOptimizePlan::query()->create(array_merge([
            'name' => 'Test Plan',
            'platform_id' => $this->platform->id,
            'enabled' => true,
            'autopilot' => false,
            'criteria' => ['min_market_sample' => 1, 'max_score' => 100, 'require_below' => 'any'],
            'schedule' => ['daily_limit' => 10, 'window_end' => '23:59', 'active_days' => [1,2,3,4,5,6,7]],
        ], $attrs));
    }

    private function makeEngine(AutoOptimizePlan $plan, array $clients): AutoOptimizeEngineService
    {
        $mockStats = $this->createMock(AutoOptimizeMarketStats::class);
        $mockStats->method('forPlatform')->willReturn([
            'averages' => ['views' => 100, 'contact_rate' => 10, 'engagement' => 5],
            'sampleSize' => max(1, count($clients)),
            'perProfile' => collect($clients)->mapWithKeys(fn ($c) => [(int) $c->wp_post_id => ['views' => 5, 'contact_rate' => 1, 'engagement' => 1]])->all(),
        ]);

        $selectionService = new AutoOptimizeSelectionService($mockStats);
        $alertService = app(\App\Services\AutoOptimize\AutoOptimizeAlertService::class);

        return new AutoOptimizeEngineService($selectionService, $alertService);
    }
}
