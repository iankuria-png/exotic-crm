<?php

namespace Tests\Feature\Kyc;

use App\Models\KycAiReview;
use App\Models\KycSubject;
use App\Services\Kyc\Ai\KycAiReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class FindingsProvenanceTest extends TestCase
{
    use InteractsWithKycFixtures, RefreshDatabase;

    private function reviewedCase(string $status = 'info_requested', bool $retainedBack = false): KycSubject
    {
        $client = $this->createClientForPlatform($this->createPlatform());
        $subject = $this->createSubjectForClient($client, ['status' => $status, 'review_version' => 4,
            'capture_set_id' => (string) Str::uuid(), 'document_type' => 'passport', 'submitted_at' => now()]);
        if ($retainedBack) {
            $this->createDbDocument($subject, 'id_back')->update(['capture_set_id' => (string) Str::uuid(), 'document_type' => 'national_id']);
        }
        $ids = [];
        foreach (['id_front', 'selfie'] as $kind) {
            $doc = $this->createDbDocument($subject, $kind);
            $doc->update(['capture_set_id' => $subject->capture_set_id, 'document_type' => 'passport']);
            $ids[] = $doc->id;
        }
        $review = KycAiReview::create(['subject_id' => $subject->id, 'status' => 'completed', 'mode' => 'auto_approve',
            'review_version' => 3, 'document_ids' => $ids, 'observations' => ['evaluation_confidence' => .86, 'legal_name' => 'PRIVATE-SYNTHETIC-OBSERVATION'],
            'action_taken' => 'info_requested', 'recommendation' => 'retake']);
        $subject->update(['submitted_document_ids' => $ids, 'ai_last_review_id' => $review->id]);

        return $subject->fresh();
    }

    public function test_decisions_retain_findings_without_relaxing_undecided_or_expired_versions(): void
    {
        foreach (['approved', 'rejected', 'info_requested', 'in_review', 'expired', 'unverified'] as $status) {
            $subject = $this->reviewedCase($status);
            $findings = app(KycAiReviewService::class)->findings($subject);
            $stale = in_array($status, ['in_review', 'expired', 'unverified']);
            $this->assertSame($stale, $findings['stale'], $status);
            $this->assertSame(! $stale, $findings['decision_changed'], $status);
            $this->assertSame($stale ? null : .86, $findings['evaluation_confidence']);
        }
    }

    public function test_old_back_from_previous_capture_does_not_hide_current_passport_findings(): void
    {
        $subject = $this->reviewedCase(retainedBack: true);
        $this->assertCount(3, $subject->documents);
        $this->assertFalse(app(KycAiReviewService::class)->findings($subject)['stale']);
    }

    public function test_changed_evidence_never_gains_a_decision_version_exemption(): void
    {
        $changes = [
            fn ($s) => $s->documents()->where('kind', 'selfie')->delete(),
            fn ($s) => $this->createDbDocument($s, 'id_back'), // Later staff upload, no capture metadata.
            fn ($s) => $s->update(['capture_set_id' => (string) Str::uuid()]),
            fn ($s) => $s->update(['document_type' => 'national_id']),
            fn ($s) => $s->update(['submitted_document_ids' => []]),
        ];
        foreach ($changes as $change) {
            $subject = $this->reviewedCase();
            $change($subject);
            $findings = app(KycAiReviewService::class)->findings($subject->fresh());
            $this->assertTrue($findings['stale']);
            $this->assertNull($findings['evaluation_confidence']);
        }
    }

    public function test_queue_exposes_only_current_non_shadow_score_including_zero(): void
    {
        $subject = $this->reviewedCase();
        $subject->aiLastReview->update(['observations' => ['evaluation_confidence' => 0, 'legal_name' => 'PRIVATE-SYNTHETIC-OBSERVATION']]);
        $this->actingAsKycUser();
        $response = $this->getJson('/api/crm/kyc/queue')->assertOk()
            ->assertJsonPath('data.0.ai_review.evaluation_confidence', 0)
            ->assertJsonPath('data.0.ai_review.stale', false)
            ->assertJsonMissingPath('data.0.documents')->assertJsonMissingPath('data.0.ai_review.observations');
        $this->assertStringNotContainsString('PRIVATE-SYNTHETIC-OBSERVATION', $response->getContent());
        $subject->aiLastReview->update(['mode' => 'shadow']);
        $this->getJson('/api/crm/kyc/queue')->assertOk()->assertJsonMissingPath('data.0.ai_review.evaluation_confidence')
            ->assertJsonMissingPath('data.0.ai_review.recommendation');
        foreach (['queued', 'failed', 'cancelled'] as $status) {
            $subject->aiLastReview->update(['mode' => 'auto_approve', 'status' => $status]);
            $this->assertNull(app(KycAiReviewService::class)->findings($subject->fresh())['evaluation_confidence']);
        }
        $subject->aiLastReview->update(['status' => 'completed', 'error' => 'superseded']);
        $this->assertNull(app(KycAiReviewService::class)->findings($subject->fresh())['evaluation_confidence']);
    }
}
