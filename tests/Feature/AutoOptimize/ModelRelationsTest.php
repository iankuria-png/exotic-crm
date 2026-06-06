<?php

namespace Tests\Feature\AutoOptimize;

use App\Models\AutoOptimizeAlert;
use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;
use App\Models\AutoOptimizeRun;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_enabled_platform_key_set_on_save(): void
    {
        $platform = $this->createPlatform();
        $plan = AutoOptimizePlan::query()->create([
            'name' => 'Test',
            'platform_id' => $platform->id,
            'enabled' => true,
        ]);

        $this->assertSame((int) $platform->id, $plan->enabled_platform_key);

        $plan->enabled = false;
        $plan->save();

        $this->assertNull($plan->fresh()->enabled_platform_key);
    }

    public function test_item_active_client_key_set_on_save(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create(['platform_id' => $platform->id]);
        [$plan, $run] = $this->createPlanAndRun($platform);

        $item = AutoOptimizeItem::query()->create([
            'auto_optimize_plan_id' => $plan->id,
            'auto_optimize_run_id' => $run->id,
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'queued',
        ]);

        $this->assertSame((int) $client->id, $item->active_client_key);

        $item->status = 'applied';
        $item->save();

        $this->assertNull($item->fresh()->active_client_key);
    }

    public function test_item_transitions_through_active_statuses(): void
    {
        $platform = $this->createPlatform();
        [$plan] = $this->createPlanAndRun($platform);

        foreach (AutoOptimizeItem::ACTIVE_STATUSES as $status) {
            // Each iteration uses a fresh client + run to avoid unique(run_id, client_id) collision
            $client = Client::factory()->create(['platform_id' => $platform->id]);
            $run = AutoOptimizeRun::query()->create([
                'auto_optimize_plan_id' => $plan->id,
                'platform_id' => $platform->id,
                'status' => 'running',
            ]);

            $item = AutoOptimizeItem::query()->create([
                'auto_optimize_plan_id' => $plan->id,
                'auto_optimize_run_id' => $run->id,
                'platform_id' => $platform->id,
                'client_id' => $client->id,
                'status' => $status,
            ]);

            $this->assertSame((int) $client->id, $item->active_client_key, "active_client_key should be set for status: {$status}");

            // Transition to terminal — active_client_key must be cleared
            $item->status = 'applied';
            $item->save();
            $this->assertNull($item->fresh()->active_client_key, "active_client_key should be NULL after terminal transition");
        }
    }

    public function test_json_casts_round_trip(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create(['platform_id' => $platform->id]);
        [$plan, $run] = $this->createPlanAndRun($platform);

        $breakdown = ['word_count' => 25, 'links' => 20, 'completeness' => 15, 'media' => 10];
        $item = AutoOptimizeItem::query()->create([
            'auto_optimize_plan_id' => $plan->id,
            'auto_optimize_run_id' => $run->id,
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'applied',
            'previous_score_breakdown' => $breakdown,
            'new_score_breakdown' => $breakdown,
            'actions_applied' => ['bio' => true, 'image' => false, 'score' => true],
            'impact_before' => ['views' => 10, 'contact_rate' => 5.0],
            'impact_after' => ['views' => 20, 'contact_rate' => 8.0],
            'impact' => ['improved' => true],
        ]);

        $fresh = $item->fresh();
        $this->assertIsArray($fresh->previous_score_breakdown);
        $this->assertSame(25, $fresh->previous_score_breakdown['word_count']);
        $this->assertIsArray($fresh->actions_applied);
        $this->assertTrue($fresh->actions_applied['bio']);
        $this->assertIsArray($fresh->impact);
        $this->assertTrue($fresh->impact['improved']);
    }

    public function test_plan_relations_load(): void
    {
        $platform = $this->createPlatform();
        $plan = AutoOptimizePlan::query()->create([
            'name' => 'Test',
            'platform_id' => $platform->id,
            'enabled' => false,
        ]);

        $run = AutoOptimizeRun::query()->create([
            'auto_optimize_plan_id' => $plan->id,
            'platform_id' => $platform->id,
            'status' => 'completed',
        ]);

        $alert = AutoOptimizeAlert::query()->create([
            'auto_optimize_plan_id' => $plan->id,
            'platform_id' => $platform->id,
            'severity' => 'info',
            'type' => 'no_candidates',
            'title' => 'No candidates found',
        ]);

        $plan->load(['platform', 'runs', 'alerts']);
        $this->assertSame($platform->id, $plan->platform->id);
        $this->assertCount(1, $plan->runs);
        $this->assertCount(1, $plan->alerts);
    }

    // Helpers

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Test ' . Str::random(4),
            'domain' => 'test-' . Str::random(4) . '.example',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'wp_api_url' => 'https://test.example/wp-json',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createPlanAndRun(Platform $platform): array
    {
        $plan = AutoOptimizePlan::query()->create([
            'name' => 'Plan',
            'platform_id' => $platform->id,
            'enabled' => false,
        ]);
        $run = AutoOptimizeRun::query()->create([
            'auto_optimize_plan_id' => $plan->id,
            'platform_id' => $platform->id,
            'status' => 'running',
        ]);
        return [$plan, $run];
    }
}
