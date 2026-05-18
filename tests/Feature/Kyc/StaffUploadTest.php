<?php

namespace Tests\Feature\Kyc;

use App\Models\AuditLog;
use App\Models\KycDocumentBlob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class StaffUploadTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithKycFixtures;

    public function test_sales_can_upload_kyc_document_from_crm_with_provenance_and_audit(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $subject = $this->createSubjectForClient($client);
        $user = $this->actingAsKycUser('sales', [$platform->id]);
        $this->setKycSettings(['active_storage_driver' => 'db', 'required_document_kinds' => ['id_front']]);

        $contents = 'whatsapp-front-id-body';
        $response = $this->post('/api/crm/kyc/subjects/' . $subject->id . '/documents', [
            'kind' => 'id_front',
            'upload_source_channel' => 'whatsapp',
            'upload_note' => 'Received via WhatsApp from the client\'s registered number.',
            'file' => UploadedFile::fake()->createWithContent('front.jpg', $contents),
        ]);

        $response->assertCreated()
            ->assertJsonPath('document.upload_origin', 'crm_staff')
            ->assertJsonPath('document.upload_source_channel', 'whatsapp')
            ->assertJsonPath('document.uploaded_by_name', $user->name);

        $document = $subject->fresh(['documents.uploadedBy'])->documents->firstOrFail();
        $blob = KycDocumentBlob::query()->where('document_id', $document->id)->firstOrFail();

        $this->assertSame('crm_staff', $document->upload_origin);
        $this->assertSame('whatsapp', $document->upload_source_channel);
        $this->assertSame('Received via WhatsApp from the client\'s registered number.', $document->upload_note);
        $this->assertSame($user->id, $document->uploaded_by_user_id);
        $this->assertSame('in_review', $subject->fresh()->status);
        $this->assertNotSame($contents, $blob->body);
        $this->assertStringNotContainsString($contents, $blob->body);

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'actor_id' => $user->id,
            'action' => 'kyc_document_uploaded_by_staff',
            'entity_type' => 'kyc_document',
            'entity_id' => $document->id,
        ]);
    }

    public function test_sales_cannot_upload_documents_for_a_market_they_do_not_own(): void
    {
        $platform = $this->createPlatform();
        $otherPlatform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $subject = $this->createSubjectForClient($client);
        $this->actingAsKycUser('sales', [$otherPlatform->id]);
        $this->setKycSettings(['active_storage_driver' => 'db', 'required_document_kinds' => ['id_front']]);

        $response = $this->post('/api/crm/kyc/subjects/' . $subject->id . '/documents', [
            'kind' => 'id_front',
            'upload_source_channel' => 'email',
            'upload_note' => 'Forwarded from support inbox.',
            'file' => UploadedFile::fake()->createWithContent('front.jpg', 'forbidden-market-body'),
        ]);

        $response->assertForbidden();
    }

    public function test_staff_upload_uses_s3_driver_when_enabled(): void
    {
        Storage::fake('s3_kyc');

        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $subject = $this->createSubjectForClient($client);
        $user = $this->actingAsKycUser('admin');
        $this->setKycSettings(['active_storage_driver' => 's3', 'required_document_kinds' => ['selfie']]);

        $response = $this->post('/api/crm/kyc/subjects/' . $subject->id . '/documents', [
            'kind' => 'selfie',
            'upload_source_channel' => 'manual_assisted',
            'upload_note' => 'Captured during assisted CRM intake.',
            'file' => UploadedFile::fake()->createWithContent('selfie.jpg', 'selfie-body'),
        ]);

        $response->assertCreated();

        $document = $subject->fresh(['documents'])->documents->firstOrFail();
        $this->assertSame('s3', $document->storage_driver);
        $this->assertSame('crm_staff', $document->upload_origin);
        $this->assertSame($user->id, $document->uploaded_by_user_id);
        $this->assertNotNull($document->s3_key);
        $this->assertSame('in_review', $subject->fresh()->status);
        Storage::disk('s3_kyc')->assertExists($document->s3_key);

        $this->assertGreaterThanOrEqual(1, AuditLog::query()->where('action', 'kyc_document_uploaded_by_staff')->where('entity_id', $document->id)->count());
    }
}
