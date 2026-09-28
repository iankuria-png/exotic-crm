<?php

namespace Tests\Feature\Kyc;

use App\Models\KycSubject;
use App\Services\Kyc\KycDocumentService;
use App\Services\Kyc\KycSubjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class SubjectLifecycleTest extends TestCase
{
    use InteractsWithKycFixtures;
    use RefreshDatabase;

    public function test_subject_moves_into_review_and_then_approved_with_verified_source(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $subject = $this->createSubjectForClient($client);
        $reviewer = $this->createKycUser('admin', [$platform->id]);
        $this->setKycSettings(['required_document_kinds' => ['id_front']]);

        $service = app(KycSubjectService::class);
        $documentService = app(KycDocumentService::class);

        $claims = [
            'subject_id' => $subject->id,
            'kind' => 'id_front',
            'max_bytes' => 5000,
            'sha256' => hash('sha256', 'subject-lifecycle-body'),
            'mime' => 'image/jpeg',
        ];

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('front.jpg', 'subject-lifecycle-body');
        $documentService->storeDbUploadFromToken($claims, $file);

        $this->assertSame(KycSubject::STATUS_IN_REVIEW, $subject->fresh()->status);

        $service->markApprovedFromSource($subject->fresh(['client', 'sites']), 'kyc', $reviewer, 'Approved in lifecycle test');

        $client->refresh();
        $subject->refresh();

        $this->assertSame(KycSubject::STATUS_APPROVED, $subject->status);
        $this->assertTrue((bool) $client->verified);
        $this->assertSame('kyc', $client->verified_source);
    }

    public function test_reverification_request_exposes_a_client_restart_path(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $subject = $this->createSubjectForClient($client, ['status' => KycSubject::STATUS_REJECTED]);
        $reviewer = $this->createKycUser('admin', [$platform->id]);

        app(KycSubjectService::class)->reRequest($subject->fresh(['client', 'sites']), $reviewer, 'Manual re-verification requested from client detail');
        $payload = app(KycSubjectService::class)->buildStatusPayload($subject->fresh('client'));

        $this->assertSame(KycSubject::STATUS_EXPIRED, $payload['status']);
        $this->assertTrue($payload['can_restart_submission']);
    }
}
