<?php

namespace Tests\Feature\Faq;

use App\Models\Faq\Article;
use App\Models\Faq\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FaqFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_feedback_flow_supports_create_vote_comments_status_updates_and_internal_visibility(): void
    {
        $admin = $this->userForRole('admin');
        $sales = $this->userForRole('sales');
        $article = Article::factory()->create([
            'status' => 'published',
        ]);

        Sanctum::actingAs($sales);

        $createResponse = $this->postJson('/api/crm/faq/feedback', [
            'article_id' => $article->id,
            'kind' => 'bug',
            'title' => 'Payment queue filter resets',
            'comment' => 'The filter clears after I return from detail view.',
            'severity' => 'high',
            'context_path' => '/payments?status=pending',
            'context_meta' => ['viewport' => '1440x900'],
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('feedback.kind', 'bug')
            ->assertJsonPath('feedback.status', 'new');

        $feedbackId = (int) $createResponse->json('feedback.id');

        $this->postJson("/api/crm/faq/feedback/{$feedbackId}/votes/toggle")
            ->assertOk()
            ->assertJsonPath('voted', true)
            ->assertJsonPath('votes_count', 1);

        $this->postJson("/api/crm/faq/feedback/{$feedbackId}/comments", [
            'body' => 'Happy to test a fix on Kenya rows.',
        ])->assertCreated();

        Sanctum::actingAs($admin);

        $this->postJson("/api/crm/faq/feedback/{$feedbackId}/comments", [
            'body' => 'Internal note for triage only.',
            'is_internal' => true,
        ])->assertCreated();

        $this->patchJson("/api/crm/faq/feedback/{$feedbackId}", [
            'status' => 'triaged',
            'admin_notes' => 'Reproduced using the pending queue tab.',
        ])->assertOk()
            ->assertJsonPath('feedback.status', 'triaged');

        Sanctum::actingAs($sales);

        $detailResponse = $this->getJson("/api/crm/faq/feedback/{$feedbackId}");
        $detailResponse->assertOk()
            ->assertJsonPath('feedback.comments_count', 2)
            ->assertJsonMissing(['is_internal' => true]);

        $detailResponse->assertJsonPath('feedback.has_unread_update', false);

        $listResponse = $this->getJson('/api/crm/faq/feedback?tab=mine');
        $listResponse->assertOk()
            ->assertJsonPath('meta.submitter_update_count', 0);

        $feedback = Feedback::query()->findOrFail($feedbackId);
        $this->assertNotNull($feedback->last_seen_at);
        $this->assertSame('triaged', $feedback->status);
        $this->assertCount(2, $feedback->status_history);
    }

    public function test_helpful_and_unhelpful_feedback_updates_article_counters(): void
    {
        $sales = $this->userForRole('sales');
        $article = Article::factory()->create([
            'status' => 'published',
            'helpful_count' => 0,
            'unhelpful_count' => 0,
        ]);

        Sanctum::actingAs($sales);

        $this->postJson('/api/crm/faq/feedback', [
            'article_id' => $article->id,
            'kind' => 'helpful',
            'helpful' => true,
        ])->assertCreated();

        $this->postJson('/api/crm/faq/feedback', [
            'article_id' => $article->id,
            'kind' => 'unhelpful',
            'helpful' => false,
            'comment' => 'I still need a queue example.',
        ])->assertCreated();

        $article->refresh();
        $this->assertSame(1, (int) $article->helpful_count);
        $this->assertSame(1, (int) $article->unhelpful_count);
    }

    private function userForRole(string $role): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }
}
