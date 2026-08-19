<?php

namespace Tests\Feature\Seo;

use App\Jobs\OptimizeProfileJob;
use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;
use App\Models\Client;
use App\Models\Platform;
use App\Models\SeoBioFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BioQualityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_scan_platform_bio_quality(): void
    {
        $platform = Platform::factory()->create(['name' => 'Kenya', 'country' => 'Kenya']);

        foreach (['Nairobi', 'Kisumu', 'Mombasa'] as $city) {
            Client::factory()->create([
                'platform_id' => $platform->id,
                'bio_original_html' => "<p>I keep things simple and real in {$city}. No games, no rush. Just good company and a better vibe.</p>",
            ]);
        }

        SeoBioFeedback::create([
            'platform_id' => $platform->id,
            'accepted' => true,
            'rating' => 1,
            'bio_html' => '<p>I am Lisa, 23, a Black escort in Kisumu. I keep things simple and real. No games, no pressure.</p>',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->getJson("/api/crm/seo/quality-audit?platform_id={$platform->id}&source=all&limit=25");

        $response->assertOk()
            ->assertJsonPath('summary.platform_id', $platform->id)
            ->assertJsonPath('summary.sample_size', 4);

        $this->assertLessThan(90, (int) $response->json('summary.quality_score'));
        $this->assertGreaterThan(0, (int) $response->json('summary.ai_likeness_score'));
        $this->assertGreaterThan(0, (float) $response->json('summary.metrics.ethnicity_mention_rate'));
        $this->assertNotEmpty($response->json('summary.top_slop_flags'));
        $this->assertNotEmpty($response->json('summary.corpus_overuse.phrases'));
        $this->assertContains('keep things', collect($response->json('summary.corpus_overuse.phrases'))->pluck('term')->all());
    }

    public function test_sub_admin_can_scan_all_platforms_but_sales_cannot(): void
    {
        $platform = Platform::factory()->create(['name' => 'Ghana', 'country' => 'Ghana']);

        SeoBioFeedback::create([
            'platform_id' => $platform->id,
            'accepted' => true,
            'rating' => 1,
            'bio_html' => '<p>Private, playful, and specific. We start with massage in Osu and move at your pace.</p>',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'sub_admin', 'status' => 'active']));
        $this->getJson('/api/crm/seo/quality-audit?source=accepted')
            ->assertOk()
            ->assertJsonPath('platforms.0.platform_id', $platform->id);

        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));
        $this->getJson('/api/crm/seo/quality-audit?source=accepted')
            ->assertStatus(403);
    }

    public function test_admin_can_stage_bio_quality_recovery_into_auto_optimizer(): void
    {
        Bus::fake();

        $platform = Platform::factory()->create(['name' => 'Kenya', 'country' => 'Kenya']);
        $bad = Client::factory()->create([
            'platform_id' => $platform->id,
            'seo_score' => 77,
            'bio_original_html' => '<p>I am Lisa, 23, a Black escort in Kisumu. I keep things simple and real. No games, no rush. Just good company and a better vibe.</p>',
        ]);
        Client::factory()->create([
            'platform_id' => $platform->id,
            'bio_original_html' => '<p>Private evenings in Kilimani work best when the mood is unhurried, warm, and clear. She prefers direct plans, useful details, and a guest who knows the kind of time he wants. Massage, dinner, and quiet company can all fit naturally when the chemistry is right and the booking is handled with respect.</p>',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->postJson('/api/crm/auto-optimize/quality-audit/run', [
            'platform_id' => $platform->id,
            'limit' => 1,
        ]);

        $response->assertOk()
            ->assertJsonPath('selected', 1)
            ->assertJsonPath('plan.name', 'Bio quality recovery');

        $plan = AutoOptimizePlan::query()->where('platform_id', $platform->id)->where('name', 'Bio quality recovery')->first();
        $this->assertNotNull($plan);
        $this->assertFalse((bool) $plan->autopilot);
        $this->assertDatabaseHas('auto_optimize_items', [
            'auto_optimize_plan_id' => $plan->id,
            'platform_id' => $platform->id,
            'client_id' => $bad->id,
            'status' => 'queued',
        ]);

        Bus::assertBatched(fn ($batch) => count($batch->jobs) === 1 && $batch->jobs[0] instanceof OptimizeProfileJob);
        $this->assertSame(1, AutoOptimizeItem::query()->where('auto_optimize_plan_id', $plan->id)->count());
    }
}
