<?php

namespace Tests\Feature\Kyc;

use App\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class S3UploadCompleteTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithKycFixtures;

    public function test_s3_complete_creates_document_and_marks_subject_in_review(): void
    {
        config(['filesystems.disks.s3_kyc.url' => 'https://s3.example.test']);
        Storage::shouldReceive('disk')->with('s3_kyc')->andReturn(new class {
            public function getClient()
            {
                return null;
            }
        });
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $this->setKycSettings(['active_storage_driver' => 's3', 'required_document_kinds' => ['id_front']]);

        $initiate = $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/uploads/initiate', [
            'platform_id' => $platform->id,
            'wp_user_id' => $client->wp_user_id,
            'wp_post_id' => $client->wp_post_id,
            'kind' => 'id_front',
            'mime' => 'image/jpeg',
            'byte_size' => 1024,
            'sha256' => str_repeat('b', 64),
        ])->assertOk()->json();

        $this->assertSame('s3', $initiate['mode']);
        $this->assertNotEmpty($initiate['s3_key']);

        $response = $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/uploads/complete', [
            'subject_id' => $initiate['subject_id'],
            'kind' => 'id_front',
            's3_key' => $initiate['s3_key'],
            'mime' => 'image/jpeg',
            'byte_size' => 1024,
            'sha256' => str_repeat('b', 64),
        ]);

        $response->assertOk()->assertJsonPath('status', 'in_review');
        $document = KycDocument::query()->firstOrFail();
        $this->assertSame('s3', $document->storage_driver);
        $this->assertSame($initiate['s3_key'], $document->s3_key);
    }
}
