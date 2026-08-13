<?php

namespace Tests\Feature\Compliance;

use App\Models\Client;
use App\Models\ContentComplianceDeclaration;
use App\Models\CreatorAgreementAcceptance;
use App\Models\CreatorAgreementVersion;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WpComplianceCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_KEY = 'compliance-shared-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exotic_crm_sync.shared_key' => self::SHARED_KEY,
            'services.seo_engine.platform_allowlist' => [],
        ]);

        User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_wp_can_record_creator_agreement_acceptance_once(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_user_id' => 321,
            'wp_post_id' => 654,
        ]);

        $body = [
            'agreement_version_key' => '2026.08',
            'agreement_title' => 'Creator Agreement',
            'agreement_body_html' => '<p>Creator must be 18+ and keep records.</p>',
            'agreement_body_sha256' => hash('sha256', '<p>Creator must be 18+ and keep records.</p>'),
            'accepted' => true,
            'wp_user_id' => 321,
            'wp_post_id' => 654,
            'source_context' => 'independent_signup',
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Feature Test Browser',
            'idempotency_key' => 'wp-acceptance-654-202608',
        ];

        $this->postJson('/api/wp-svc/compliance/creator-agreement/acceptances', $body, $this->signHeaders($platform->id, $body))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('client_id', $client->id)
            ->assertJsonPath('version_key', '2026.08');

        $this->postJson('/api/wp-svc/compliance/creator-agreement/acceptances', $body, $this->signHeaders($platform->id, $body))
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertSame(1, CreatorAgreementVersion::query()->count());
        $this->assertSame(1, CreatorAgreementAcceptance::query()->count());
    }

    public function test_acceptance_requires_affirmative_true_value(): void
    {
        $platform = Platform::factory()->create();
        $body = [
            'agreement_version_key' => '2026.08',
            'accepted' => false,
            'wp_user_id' => 321,
            'source_context' => 'profile_edit',
            'idempotency_key' => 'not-accepted',
        ];

        $this->postJson('/api/wp-svc/compliance/creator-agreement/acceptances', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Creator agreement must be affirmatively accepted.');

        $this->assertSame(0, CreatorAgreementAcceptance::query()->count());
    }

    public function test_wp_can_record_solo_content_declaration(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_user_id' => 321,
            'wp_post_id' => 654,
        ]);

        $body = [
            'wp_user_id' => 321,
            'wp_post_id' => 654,
            'wp_attachment_id' => 987,
            'content_kind' => 'profile_photo',
            'participant_status' => 'solo',
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Feature Test Browser',
            'idempotency_key' => 'content-987-solo',
        ];

        $this->postJson('/api/wp-svc/compliance/content-declarations', $body, $this->signHeaders($platform->id, $body))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('client_id', $client->id)
            ->assertJsonPath('status', 'accepted');

        $this->assertDatabaseHas('content_compliance_declarations', [
            'client_id' => $client->id,
            'wp_attachment_id' => 987,
            'participant_status' => 'solo',
            'status' => 'accepted',
        ]);
    }

    public function test_other_people_declaration_is_recorded_but_blocked_in_solo_only_mode(): void
    {
        $platform = Platform::factory()->create();
        Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_user_id' => 321,
            'wp_post_id' => 654,
        ]);

        $body = [
            'wp_user_id' => 321,
            'wp_post_id' => 654,
            'content_kind' => 'profile_video',
            'participant_status' => 'other_people_declared',
            'idempotency_key' => 'content-blocked-001',
        ];

        $this->postJson('/api/wp-svc/compliance/content-declarations', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'blocked_pending_release');

        $this->assertSame(1, ContentComplianceDeclaration::query()->where('status', 'blocked_pending_release')->count());
    }

    private function signHeaders(int $platformId, array $body): array
    {
        $timestamp = time();
        $bodyJson = json_encode($body);

        return [
            'X-Exotic-CRM-Sync-Key' => self::SHARED_KEY,
            'X-Exotic-Platform-Id' => (string) $platformId,
            'X-Exotic-Timestamp' => (string) $timestamp,
            'X-Exotic-Signature' => hash_hmac('sha256', $timestamp.'.'.$bodyJson, self::SHARED_KEY),
        ];
    }
}
