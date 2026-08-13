<?php

namespace Tests\Feature\Compliance;

use App\Models\Client;
use App\Models\ContentComplianceDeclaration;
use App\Models\CreatorAgreementAcceptance;
use App\Models\CreatorAgreementVersion;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ComplianceEvidencePackTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_compliance_payload_summarizes_agreement_and_declarations(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_user_id' => 321,
            'wp_post_id' => 654,
        ]);
        $version = CreatorAgreementVersion::query()->create([
            'version_key' => '2026.08',
            'title' => 'Creator Agreement',
            'body_html' => '<p>Agreement</p>',
            'body_sha256' => hash('sha256', '<p>Agreement</p>'),
            'published_at' => now(),
        ]);
        CreatorAgreementAcceptance::query()->create([
            'agreement_version_id' => $version->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'wp_user_id' => 321,
            'wp_post_id' => 654,
            'source_context' => 'profile_edit',
            'accepted_at' => now(),
            'wp_idempotency_key' => 'acceptance-existing',
        ]);
        ContentComplianceDeclaration::query()->create([
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'wp_user_id' => 321,
            'wp_post_id' => 654,
            'wp_attachment_id' => 987,
            'content_kind' => 'profile_photo',
            'participant_status' => 'solo',
            'status' => 'accepted',
            'declared_at' => now(),
            'wp_idempotency_key' => 'declaration-existing',
        ]);

        $this->getJson("/api/crm/clients/{$client->id}/compliance")
            ->assertOk()
            ->assertJsonPath('creator_agreement.status', 'accepted')
            ->assertJsonPath('creator_agreement.latest.version_key', '2026.08')
            ->assertJsonPath('content_compliance.status', 'ok')
            ->assertJsonPath('content_compliance.items.0.wp_attachment_id', 987);
    }

    public function test_admin_can_generate_evidence_pack_export(): void
    {
        Storage::fake('local');

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        $this->postJson("/api/crm/clients/{$client->id}/compliance/evidence-pack", [
            'reason' => 'Segpay record request',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['export_id', 'download_url', 'expires_at']);

        $export = $client->complianceEvidenceExports()->first();
        $this->assertNotNull($export);
        Storage::disk('local')->assertExists($export->storage_path);
    }

    public function test_sales_user_cannot_generate_evidence_pack_export(): void
    {
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active']);
        Sanctum::actingAs($sales);

        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        $this->postJson("/api/crm/clients/{$client->id}/compliance/evidence-pack", [
            'reason' => 'Segpay record request',
        ])->assertStatus(403);
    }
}
