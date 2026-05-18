<?php

namespace Tests\Feature\Kyc;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class DocumentBlobViewTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithKycFixtures;

    public function test_signed_document_view_streams_db_content_and_audits_access(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $subject = $this->createSubjectForClient($client);
        $document = $this->createDbDocument($subject, 'id_front', 'db-view-body');
        $user = $this->actingAsKycUser('admin', [$platform->id]);

        $review = $this->getJson('/api/crm/kyc/subjects/' . $subject->id)->assertOk()->json();
        $path = $this->signedPathFromUrl($review['documents'][0]['view_url']);

        $response = $this->get($path);

        $response->assertOk();
        $this->assertSame('db-view-body', $response->getContent());
        $this->assertGreaterThanOrEqual(1, AuditLog::query()->where('action', 'kyc_document_viewed')->count());
    }
}
