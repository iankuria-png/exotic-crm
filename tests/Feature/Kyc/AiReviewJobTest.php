<?php

namespace Tests\Feature\Kyc;

use App\Jobs\Kyc\RunKycAiReviewJob;
use App\Models\KycAiReview;
use App\Services\Kyc\Ai\KycAiPolicy;
use App\Services\Kyc\Ai\KycAiReviewService;
use App\Services\Kyc\Ai\KycVisionClient;
use App\Services\Kyc\KycSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;
use Tests\Unit\Kyc\KycAiPolicyTest;

class AiReviewJobTest extends TestCase
{
    use InteractsWithKycFixtures, RefreshDatabase;

    private function review(string $mode = 'auto_approve'): KycAiReview
    {
        Queue::fake();
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $subject = $this->createSubjectForClient($client, ['status' => 'in_review', 'document_type' => 'national_id', 'submitted_at' => now(), 'ai_consent_at' => now()]);
        $ids = [];
        foreach ([['id_front', 0], ['id_back', 0], ['selfie', 0], ['selfie', 1], ['selfie', 2]] as [$kind, $sequence]) {
            $doc = $this->createDbDocument($subject, $kind, $kind.$sequence);
            $doc->update(['sequence' => $sequence]);
            $ids[] = $doc->id;
        }
        $subject->update(['submitted_document_ids' => $ids]);
        $this->setKycSettings(['enabled_platform_ids' => [$platform->id], 'ai_review' => ['mode' => $mode, 'qa_sample_pct' => 100]]);

        return app(KycAiReviewService::class)->queue($subject);
    }

    private function runReview(KycAiReview $review, ?array $observations = null, ?callable $during = null): void
    {
        $vision = $this->mock(KycVisionClient::class);
        $vision->shouldReceive('inspect')->andReturnUsing(function () use ($observations, $during) {
            if ($during) {
                $during();
            }

            return $observations ?? KycAiPolicyTest::clean();
        });
        (new RunKycAiReviewJob($review->id))->handle($vision, new KycAiPolicy, app(KycSettingsService::class));
    }

    public function test_clean_approval_updates_existing_badge_contract_and_selects_qa(): void
    {
        $review = $this->review();
        $this->runReview($review);
        $review->refresh();
        $this->assertSame('completed', $review->status);
        $this->assertSame('approved', $review->action_taken);
        $this->assertTrue($review->qa_sample);
        $this->assertTrue($review->subject->client->verified);
        $this->assertSame('kyc', $review->subject->client->verified_source);
    }

    public function test_replacement_during_provider_call_cannot_apply_old_approval(): void
    {
        $review = $this->review();
        $this->runReview($review, null, fn () => $review->subject->increment('review_version'));
        $this->assertSame('none', $review->fresh()->action_taken);
        $this->assertFalse($review->subject->client->verified);
    }

    public function test_kill_switch_during_call_prevents_action(): void
    {
        $review = $this->review();
        $this->runReview($review, null, fn () => $this->setKycSettings(['ai_review' => ['mode' => 'off']]));
        $this->assertSame('none', $review->fresh()->action_taken);
        $this->assertSame('in_review', $review->subject->status);
    }

    public function test_shadow_does_not_write_extracted_identity_or_take_action(): void
    {
        $review = $this->review('shadow');
        $this->runReview($review);
        $this->assertSame('none', $review->fresh()->action_taken);
        $this->assertNull($review->subject->legal_name);
        $this->assertSame('in_review', $review->subject->status);
    }

    public function test_uploaded_single_selfie_cannot_be_auto_approved(): void
    {
        $review = $this->review();
        $review->subject->documents()->where('kind', 'selfie')->where('sequence', '>', 0)->delete();
        $ids = $review->subject->documents()->orderBy('id')->pluck('id')->all();
        $review->update(['document_ids' => $ids]);
        $review->subject->update(['submitted_document_ids' => $ids]);
        $this->runReview($review);
        $this->assertSame('human', $review->fresh()->recommendation);
        $this->assertFalse($review->subject->client->verified);
    }

    public function test_quality_issue_requests_only_the_affected_photo(): void
    {
        $review = $this->review();
        $o = KycAiPolicyTest::clean();
        $o['quality_problems'] = [['kind' => 'id_front', 'reason' => 'glare']];
        $this->runReview($review, $o);
        $this->assertSame('info_requested', $review->fresh()->action_taken);
        $this->assertSame(['id_front'], $review->fresh()->retake);
        $this->assertSame('info_requested', $review->subject->status);
    }

    public function test_human_review_wins_and_agreement_is_recorded(): void
    {
        $review = $this->review('shadow');
        $this->runReview($review);
        $this->actingAsKycUser();
        $this->postJson('/api/crm/kyc/subjects/'.$review->subject_id.'/approve')->assertOk();
        $this->assertTrue($review->fresh()->human_agreed);
        $this->assertSame('approve', $review->fresh()->human_decision);
    }

    public function test_failed_schema_never_retries_with_another_vendor_or_decides(): void
    {
        $review = $this->review();
        $vision = $this->mock(KycVisionClient::class);
        $vision->shouldReceive('inspect')->once()->andThrow(new \RuntimeException('invalid_observation_schema'));
        (new RunKycAiReviewJob($review->id))->handle($vision, new KycAiPolicy, app(KycSettingsService::class));
        $this->assertSame('failed', $review->fresh()->status);
        $this->assertSame('in_review', $review->subject->status);
    }

    public function test_paused_before_worker_makes_no_provider_request(): void
    {
        $review = $this->review();
        $this->setKycSettings(['ai_review' => ['mode' => 'auto_approve', 'paused' => true]]);
        $vision = $this->mock(KycVisionClient::class);
        $vision->shouldNotReceive('inspect');
        (new RunKycAiReviewJob($review->id))->handle($vision, new KycAiPolicy, app(KycSettingsService::class));
        $this->assertSame('cancelled', $review->fresh()->status);
        $this->assertEquals(0, $review->fresh()->reserved_usd);
    }

    public function test_human_filter_includes_advisory_and_stalled_reviews_and_respects_markets(): void
    {
        $review = $this->review('advisory');
        $platformId = $review->subject->client->platform_id;
        $this->actingAsKycUser('sales', [$platformId]);
        $this->getJson('/api/crm/kyc/queue?ai_filter=needs_human')->assertOk()->assertJsonPath('total', 1);
        $review->update(['mode' => 'auto_approve']);
        $this->getJson('/api/crm/kyc/queue?ai_filter=needs_human')->assertOk()->assertJsonPath('total', 0);
        $review->timestamps = false;
        $review->update(['updated_at' => now()->subMinutes(11)]);
        $this->getJson('/api/crm/kyc/queue?ai_filter=needs_human')->assertOk()->assertJsonPath('total', 1);
        $this->actingAsKycUser('sales', [$this->createPlatform()->id]);
        $this->getJson('/api/crm/kyc/queue?ai_filter=needs_human')->assertOk()->assertJsonPath('total', 0);
        $this->postJson('/api/crm/kyc/subjects/'.$review->subject_id.'/ai-review')->assertForbidden();
    }

    public function test_replacement_cancels_old_queued_work_and_reserves_new_set(): void
    {
        $review = $this->review();
        $subject = $review->subject;
        $subject->increment('review_version');
        $new = app(KycAiReviewService::class)->queue($subject);
        $this->assertSame('cancelled', $review->fresh()->status);
        $this->assertEquals(0, $review->fresh()->reserved_usd);
        $this->assertSame('queued', $new->status);
        $this->assertSame($new->id, $subject->fresh()->ai_last_review_id);
    }
}
