<?php

namespace Tests\Feature\Kyc;

use App\Jobs\Kyc\RunKycAiReviewJob;
use App\Services\Kyc\Ai\KycAiReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class GuidedSubmissionTest extends TestCase
{
    use InteractsWithKycFixtures, RefreshDatabase;

    private function submission(string $type = 'national_id', array $kinds = ['id_front', 'id_back', 'selfie']): array
    {
        Queue::fake();
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $set = (string) Str::uuid();
        $this->setKycSettings(['enabled_platform_ids' => [$platform->id], 'ai_review' => ['mode' => 'shadow']]);
        $subject = $this->createSubjectForClient($client, ['capture_set_id' => $set, 'document_type' => $type]);
        $ids = [];
        foreach ($kinds as $kind) {
            $doc = $this->createDbDocument($subject, $kind);
            $doc->update(['capture_set_id' => $set, 'document_type' => $type]);
            $ids[] = $doc->id;
        }

        return [$subject, ['platform_id' => $platform->id, 'wp_user_id' => $client->wp_user_id, 'wp_post_id' => $client->wp_post_id, 'document_type' => $type, 'capture_set_id' => $set, 'document_ids' => $ids, 'consent' => true]];
    }

    public function test_passport_requires_no_back_and_queues_only_once_after_submit(): void
    {
        [$subject, $payload] = $this->submission('passport', ['id_front', 'selfie']);
        $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/uploads/submit', $payload)->assertOk()->assertJsonPath('status', 'in_review');
        $this->postJson('/api/kyc/uploads/submit', $payload)->assertOk();
        Queue::assertPushed(RunKycAiReviewJob::class, 1);
        $this->assertNotNull($subject->fresh()->ai_consent_at);
    }

    public function test_id_back_is_required_and_consent_is_required(): void
    {
        [$subject, $payload] = $this->submission('national_id', ['id_front', 'selfie']);
        $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/uploads/submit', $payload)->assertStatus(422);
        $payload['consent'] = false;
        $this->postJson('/api/kyc/uploads/submit', $payload)->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_wrong_identity_and_stale_document_ids_cannot_submit(): void
    {
        [$subject, $payload] = $this->submission();
        $wrong = $payload;
        $wrong['wp_user_id']++;
        $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/uploads/submit', $wrong)->assertNotFound();
        $payload['document_ids'][0] = 99999;
        $this->postJson('/api/kyc/uploads/submit', $payload)->assertStatus(409);
    }

    public function test_disabled_document_type_and_market_are_rejected(): void
    {
        [$subject, $payload] = $this->submission('alien_id');
        $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/uploads/submit', $payload)->assertStatus(422);
        $this->setKycSettings(['enabled_platform_ids' => []]);
        $this->postJson('/api/kyc/uploads/submit', $payload)->assertStatus(422);
    }

    public function test_daily_cap_preserves_human_queue_and_off_sends_no_job(): void
    {
        [$subject, $payload] = $this->submission();
        $this->setKycSettings(['ai_review' => ['mode' => 'shadow', 'daily_cap_usd' => 0]]);
        $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/uploads/submit', $payload)->assertOk()->assertJsonPath('ai_review', 'skipped_cap');
        Queue::assertNothingPushed();
        $this->assertSame('in_review', $subject->fresh()->status);
        $this->setKycSettings(['ai_review' => ['mode' => 'off']]);
        $this->assertNull(app(KycAiReviewService::class)->queue($subject));
    }

    public function test_legacy_upload_behavior_remains_but_guided_upload_waits_for_submit(): void
    {
        [$subject] = $this->submission();
        $service = app(\App\Services\Kyc\KycSubjectService::class);
        $this->assertSame('unverified', $service->afterDocumentUploaded($subject)->status);
        $subject->update(['capture_set_id' => null]);
        $this->assertSame('in_review', $service->afterDocumentUploaded($subject)->status);
    }

    public function test_shadow_findings_do_not_leak_through_subject_serialization(): void
    {
        [$subject, $payload] = $this->submission();
        $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/uploads/submit', $payload)->assertOk();
        $subject = $subject->fresh();
        $subject->aiLastReview->update(['observations' => ['legal_name' => 'Secret Synthetic Name'], 'recommendation' => 'approve']);
        $this->actingAsKycUser();
        $response = $this->getJson('/api/crm/kyc/subjects/'.$subject->id)->assertOk();
        $this->assertStringNotContainsString('Secret Synthetic Name', $response->getContent());
        $this->assertStringNotContainsString('recommendation', $response->getContent());
    }
}
