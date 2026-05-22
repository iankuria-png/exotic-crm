<?php

namespace Tests\Feature\Seo;

use App\Models\Platform;
use App\Models\SeoBioFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeoFeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.seo_engine.enabled' => true]);
    }

    public function test_authenticated_user_can_record_feedback(): void
    {
        $platform = Platform::factory()->create();
        $user = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/seo/feedback', [
            'platform_id'   => $platform->id,
            'provider_used' => 'deepseek',
            'rating'        => -1,
            'tag'           => 'too_generic',
            'comment'       => 'Felt like every other AI bio',
            'accepted'      => false,
            'score'         => 65,
        ]);

        $response->assertOk()->assertJsonPath('message', 'Feedback recorded.');

        $this->assertDatabaseHas('seo_bio_feedback', [
            'platform_id'   => $platform->id,
            'user_id'       => $user->id,
            'provider_used' => 'deepseek',
            'rating'        => -1,
            'tag'           => 'too_generic',
            'accepted'      => false,
        ]);
    }

    public function test_feedback_rejects_unknown_tag(): void
    {
        $platform = Platform::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        $this->postJson('/api/crm/seo/feedback', [
            'platform_id' => $platform->id,
            'tag'         => 'completely_invalid_tag_xyz',
        ])->assertStatus(422);
    }

    public function test_feedback_summary_aggregates_recent_rows(): void
    {
        $platform = Platform::factory()->create();
        $user = User::factory()->create(['role' => 'sales', 'status' => 'active']);

        SeoBioFeedback::create([
            'platform_id' => $platform->id,
            'user_id'     => $user->id,
            'rating'      => -1,
            'tag'         => 'too_generic',
        ]);
        SeoBioFeedback::create([
            'platform_id' => $platform->id,
            'user_id'     => $user->id,
            'rating'      => -1,
            'tag'         => 'too_generic',
        ]);
        SeoBioFeedback::create([
            'platform_id' => $platform->id,
            'user_id'     => $user->id,
            'rating'      => 1,
            'tag'         => 'perfect',
            'accepted'    => true,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/seo/feedback/summary?platform_id={$platform->id}");

        $response->assertOk()
            ->assertJsonPath('total_recent', 3)
            ->assertJsonPath('negative', 2)
            ->assertJsonPath('positive', 1)
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('top_tags.too_generic', 2);

        // Prompt injection should contain the "too_generic" instruction
        $this->assertStringContainsString(
            'generic',
            (string) $response->json('prompt_injection')
        );
    }

    public function test_feedback_summary_empty_for_platform_with_no_rows(): void
    {
        $platform = Platform::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        $this->getJson("/api/crm/seo/feedback/summary?platform_id={$platform->id}")
            ->assertOk()
            ->assertJsonPath('total_recent', 0)
            ->assertJsonPath('prompt_injection', '');
    }
}
