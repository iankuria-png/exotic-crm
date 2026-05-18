<?php

namespace Tests\Feature\Kyc;

use App\Models\KycDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class StorageSwitchTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithKycFixtures;

    public function test_existing_db_documents_remain_readable_after_switching_new_uploads_to_s3(): void
    {
        Storage::shouldReceive('disk')->with('s3_kyc')->andReturn(new class {
            public function temporaryUrl($path)
            {
                return 'https://s3.example.test/' . ltrim($path, '/');
            }

            public function delete(): void
            {
            }
        });

        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $subject = $this->createSubjectForClient($client);
        $dbDocument = $this->createDbDocument($subject, 'id_front', 'legacy-db-body');
        $this->setKycSettings(['active_storage_driver' => 's3']);

        $s3Document = KycDocument::query()->create([
            'subject_id' => $subject->id,
            'kind' => 'selfie',
            'storage_driver' => 's3',
            'mime' => 'image/jpeg',
            'byte_size' => 123,
            'sha256' => hash('sha256', 's3-body'),
            's3_disk' => 's3_kyc',
            's3_key' => 'kyc/' . $subject->id . '/selfie.jpg',
            'uploaded_at' => now(),
        ]);

        $user = $this->actingAsKycUser('admin', [$platform->id]);
        $dbPath = $this->signedPathFromUrl((string) URL::temporarySignedRoute('api.crm.kyc.documents.blob', now()->addMinute(), ['document' => $dbDocument->id]));
        $s3Path = $this->signedPathFromUrl((string) URL::temporarySignedRoute('api.crm.kyc.documents.blob', now()->addMinute(), ['document' => $s3Document->id]));

        $this->get($dbPath)->assertOk();
        $this->get($s3Path)->assertRedirect('https://s3.example.test/kyc/' . $subject->id . '/selfie.jpg');
    }
}
