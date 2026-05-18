<?php

namespace Tests\Feature\Kyc;

use App\Models\KycDocumentBlob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class BlobUploadTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithKycFixtures;

    public function test_db_blob_upload_stores_ciphertext_only(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $this->setKycSettings(['active_storage_driver' => 'db', 'required_document_kinds' => ['id_front']]);

        $fileContents = 'blob-upload-body';
        $initiate = $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/uploads/initiate', [
            'platform_id' => $platform->id,
            'wp_user_id' => $client->wp_user_id,
            'wp_post_id' => $client->wp_post_id,
            'kind' => 'id_front',
            'mime' => 'image/jpeg',
            'byte_size' => strlen($fileContents),
            'sha256' => hash('sha256', $fileContents),
        ])->assertOk()->json();

        $token = preg_replace('/^Bearer\s+/i', '', $initiate['upload']['headers']['Authorization'] ?? '');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post('/api/kyc/uploads/blob', [
                'file' => UploadedFile::fake()->createWithContent('front.jpg', $fileContents),
            ]);

        $response->assertCreated();
        $blob = KycDocumentBlob::query()->firstOrFail();

        $this->assertNotSame($fileContents, $blob->body);
        $this->assertStringNotContainsString($fileContents, $blob->body);
        $this->assertSame('in_review', $client->fresh()->kycSubject->status);
    }
}
