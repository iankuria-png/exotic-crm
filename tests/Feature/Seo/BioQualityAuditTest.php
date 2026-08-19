<?php

namespace Tests\Feature\Seo;

use App\Models\Client;
use App\Models\Platform;
use App\Models\SeoBioFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BioQualityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_scan_platform_bio_quality(): void
    {
        $platform = Platform::factory()->create(['name' => 'Kenya', 'country' => 'Kenya']);

        Client::factory()->count(4)->create([
            'platform_id' => $platform->id,
            'bio_original_html' => '<p>I keep things simple and real. No games, no rush. Just good company and a better vibe.</p>',
        ]);

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
            ->assertJsonPath('summary.sample_size', 2);

        $this->assertLessThan(90, (int) $response->json('summary.quality_score'));
        $this->assertGreaterThan(0, (int) $response->json('summary.ai_likeness_score'));
        $this->assertGreaterThan(0, (float) $response->json('summary.metrics.ethnicity_mention_rate'));
        $this->assertNotEmpty($response->json('summary.top_slop_flags'));
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
}
