<?php

namespace Tests\Feature\Kyc;

use App\Models\AuditLog;
use App\Services\ClientSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_field_sales_cannot_set_manual_crm_emergency_verified_true(): void
    {
        $platform = $this->createPlatform([
            'wp_api_url' => 'https://sync.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = $this->createClientForPlatform($platform);
        Sanctum::actingAs($this->createKycUser('field_sales', [$platform->id]));

        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $response = $this->postJson('/api/crm/clients/' . $client->id . '/verified-status', [
            'verified' => true,
            'source' => 'manual_crm_emergency',
            'reason' => 'Field agent trying to self-serve a badge',
        ]);

        $response->assertStatus(403);
        Http::assertNothingSent();
        $this->assertSame(0, AuditLog::query()->where('action', 'client_verified_emergency_set')->count());
    }

    public function test_reviewer_roles_can_apply_emergency_verification(): void
    {
        $base = 'https://sync.example.test/wp-json/exotic-crm-sync/v1';

        foreach (['sub_admin', 'sales'] as $role) {
            $platform = $this->createPlatform([
                'wp_api_url' => $base,
                'wp_api_user' => 'crm-user',
                'wp_api_password' => 'secret',
            ]);
            $client = $this->createClientForPlatform($platform, ['wp_post_id' => 4400]);
            Sanctum::actingAs($this->createKycUser($role, [$platform->id]));

            Http::fake(['*' => Http::response(['success' => true, 'verified' => true], 200)]);

            $response = $this->postJson('/api/crm/clients/' . $client->id . '/verified-status', [
                'verified' => true,
                'source' => 'manual_crm_emergency',
                'reason' => 'Verified in person at the branch office.',
            ]);

            $response->assertOk();
            $this->assertTrue((bool) $client->fresh()->verified, "{$role} should be able to emergency verify");
            $this->assertSame('manual_crm_emergency', $client->fresh()->verified_source);
        }
    }

    public function test_emergency_verification_still_requires_an_explicit_reason(): void
    {
        $platform = $this->createPlatform([
            'wp_api_url' => 'https://sync.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = $this->createClientForPlatform($platform);
        Sanctum::actingAs($this->createKycUser('sales', [$platform->id]));

        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $response = $this->postJson('/api/crm/clients/' . $client->id . '/verified-status', [
            'verified' => true,
            'source' => 'manual_crm_emergency',
            'reason' => '   ',
        ]);

        $response->assertStatus(422);
        Http::assertNothingSent();
        $this->assertSame(0, AuditLog::query()->where('action', 'client_verified_emergency_set')->count());
    }

    public function test_sales_cannot_emergency_verify_a_client_in_another_market(): void
    {
        $base = 'https://sync.example.test/wp-json/exotic-crm-sync/v1';
        $ownMarket = $this->createPlatform(['wp_api_url' => $base, 'wp_api_user' => 'u', 'wp_api_password' => 'p']);
        $otherMarket = $this->createPlatform(['wp_api_url' => $base, 'wp_api_user' => 'u', 'wp_api_password' => 'p']);
        $client = $this->createClientForPlatform($otherMarket);
        Sanctum::actingAs($this->createKycUser('sales', [$ownMarket->id]));

        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $response = $this->postJson('/api/crm/clients/' . $client->id . '/verified-status', [
            'verified' => true,
            'source' => 'manual_crm_emergency',
            'reason' => 'Reaching across markets',
        ]);

        $this->assertContains($response->status(), [403, 404]);
        Http::assertNothingSent();
        $this->assertFalse((bool) $client->fresh()->verified);
    }

    public function test_emergency_verify_writes_through_the_dedicated_wp_verified_route(): void
    {
        $base = 'https://sync.example.test/wp-json/exotic-crm-sync/v1';
        $platform = $this->createPlatform([
            'wp_api_url' => $base,
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $client = $this->createClientForPlatform($platform, ['wp_post_id' => 19633]);
        Sanctum::actingAs($this->createKycUser('admin'));

        Http::fake(['*' => Http::response(['success' => true, 'verified' => true], 200)]);

        $response = $this->postJson('/api/crm/clients/' . $client->id . '/verified-status', [
            'verified' => true,
            'source' => 'manual_crm_emergency',
            'reason' => 'Client verified in person at the Nairobi office.',
        ]);

        $response->assertOk();

        // The generic profile-update route blocks `verified` outright, so the
        // badge must never be pushed through it.
        Http::assertSent(fn ($request) => $request->url() === "{$base}/clients/19633/verified"
            && $request['verified'] === true);
        Http::assertNotSent(fn ($request) => $request->url() === "{$base}/clients/19633/update");

        $this->assertSame(1, AuditLog::query()->where('action', 'client_verified_emergency_set')->count());
    }
}
