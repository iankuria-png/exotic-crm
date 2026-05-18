<?php

namespace Tests\Feature\Kyc;

use App\Models\AuditLog;
use App\Services\ClientSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class VerifiedSourceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithKycFixtures;

    public function test_wordpress_import_stamps_manual_wp_on_first_verified_sync(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform, ['wp_post_id' => 12345, 'verified' => false]);

        $client->forceFill([
            'verified' => true,
            'verified_source' => 'manual_wp',
            'verified_source_at' => now(),
            'verified_source_reason' => 'Imported verified state from WordPress.',
        ])->save();

        $service = new ClientSyncService($platform);
        $method = new \ReflectionMethod($service, 'markSubjectApprovedFromWp');
        $method->invoke($service, $client);

        $client->refresh();
        $this->assertSame('manual_wp', $client->verified_source);
        $this->assertNotNull($client->kycSubject);
        $this->assertSame('approved', $client->kycSubject->status);
    }

    public function test_non_admin_cannot_set_manual_crm_emergency_verified_true(): void
    {
        $platform = $this->createPlatform([
            'wp_api_url' => 'https://sync.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = $this->createClientForPlatform($platform);
        Sanctum::actingAs($this->createKycUser('sales', [$platform->id]));

        $response = $this->postJson('/api/crm/clients/' . $client->id . '/verified-status', [
            'verified' => true,
            'source' => 'manual_crm_emergency',
            'reason' => 'Trying to bypass admin-only flow',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, AuditLog::query()->where('action', 'client.verified_emergency_set')->count());
    }
}
