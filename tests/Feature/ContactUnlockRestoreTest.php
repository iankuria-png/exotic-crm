<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContactUnlockEvent;
use App\Models\ContactUnlockSession;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Models\VisitorContactUnlock;
use App\Services\ContactUnlockAccessService;
use App\Support\ClientLifecycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ContactUnlockRestoreTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_KEY = 'contact-unlock-restore-key';
    private const PAYING_PHONE = '254711000111';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exotic_crm_sync.shared_key' => self::SHARED_KEY,
            'services.wp_service_auth.platform_allowlist' => [],
        ]);

        User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_paying_number_restores_market_access_on_a_new_browser_without_breaking_the_original(): void
    {
        $platform = Platform::factory()->create(['phone_prefix' => '254']);
        $target = $this->restrictedClient($platform, 9101, '254722333444');
        [, $originalToken, $originalSession] = $this->unlock($platform, VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES);
        $newSession = 'new-browser-'.Str::random(32);

        $restore = $this->svc($platform, 'restore', [
            'visitor_phone' => '0711000111',
            'session_proof' => $newSession,
            'target_wp_post_id' => $target->wp_post_id,
        ])->assertOk()
            ->assertJsonPath('restored', true)
            ->assertJsonPath('covers_target', true)
            ->assertJsonPath('grants.0.scope', VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES);

        $restoredToken = (string) $restore->json('grants.0.public_token');

        $this->svc($platform, 'reveal', [
            'public_token' => $restoredToken,
            'session_proof' => $newSession,
            'target_wp_post_id' => $target->wp_post_id,
        ])->assertOk()->assertJsonPath('contact.phone', '254722333444');

        $this->svc($platform, 'reveal', [
            'public_token' => $originalToken,
            'session_proof' => $originalSession,
            'target_wp_post_id' => $target->wp_post_id,
        ])->assertOk();

        $this->assertSame(1, ContactUnlockSession::query()->count());
        $this->assertSame(1, ContactUnlockEvent::query()->where('event_type', ContactUnlockEvent::TYPE_ACCESS_RESTORED)->count());
    }

    public function test_a_pass_that_has_ended_cannot_be_restored(): void
    {
        $platform = Platform::factory()->create(['phone_prefix' => '254']);
        $target = $this->restrictedClient($platform, 9106);
        // Paid 8 days ago for a 7-day pass: still marked active, but past its expiry.
        [$unlock] = $this->unlock($platform, VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES);
        $unlock->forceFill(['starts_at' => now()->subDays(8), 'expires_at' => now()->subDay()])->save();

        $this->svc($platform, 'restore', [
            'visitor_phone' => '0711000111',
            'session_proof' => 'new-browser-'.Str::random(32),
            'target_wp_post_id' => $target->wp_post_id,
        ])->assertStatus(422)->assertJsonValidationErrors('visitor_phone');

        $this->assertSame(0, ContactUnlockSession::query()->count());
    }

    public function test_restored_access_ends_with_the_original_pass_and_never_includes_email(): void
    {
        $platform = Platform::factory()->create(['phone_prefix' => '254']);
        $target = $this->restrictedClient($platform, 9107);
        $target->forceFill(['email' => 'advertiser@example.com'])->save();
        [$unlock] = $this->unlock($platform, VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES);
        $session = 'new-browser-'.Str::random(32);

        $restore = $this->svc($platform, 'restore', [
            'visitor_phone' => '0711000111',
            'session_proof' => $session,
            'target_wp_post_id' => $target->wp_post_id,
        ])->assertOk()
            ->assertJsonPath('grants.0.expires_at', $unlock->expires_at->toIso8601String());
        $reveal = [
            'public_token' => (string) $restore->json('grants.0.public_token'),
            'session_proof' => $session,
            'target_wp_post_id' => $target->wp_post_id,
        ];

        $this->svc($platform, 'reveal', $reveal)->assertOk()->assertJsonMissingPath('contact.email');

        $this->travelTo($unlock->expires_at->copy()->addMinute());
        $this->svc($platform, 'reveal', $reveal)->assertStatus(422);
    }

    public function test_unknown_number_is_refused_without_linking_anything(): void
    {
        $platform = Platform::factory()->create(['phone_prefix' => '254']);
        $target = $this->restrictedClient($platform, 9102);
        $this->unlock($platform, VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES);

        $this->svc($platform, 'restore', [
            'visitor_phone' => '0799999999',
            'session_proof' => 'new-browser-'.Str::random(32),
            'target_wp_post_id' => $target->wp_post_id,
        ])->assertStatus(422)->assertJsonValidationErrors('visitor_phone');

        $this->assertSame(0, ContactUnlockSession::query()->count());
    }

    public function test_restore_attempts_are_throttled_per_session(): void
    {
        $platform = Platform::factory()->create(['phone_prefix' => '254']);
        $target = $this->restrictedClient($platform, 9103);
        $session = 'new-browser-'.Str::random(32);

        foreach (range(1, 5) as $attempt) {
            $this->svc($platform, 'restore', [
                'visitor_phone' => '07000000'.str_pad((string) $attempt, 2, '0', STR_PAD_LEFT),
                'session_proof' => $session,
                'target_wp_post_id' => $target->wp_post_id,
            ])->assertStatus(422);
        }

        $this->svc($platform, 'restore', [
            'visitor_phone' => '0711000111',
            'session_proof' => $session,
            'target_wp_post_id' => $target->wp_post_id,
        ])->assertStatus(429);
    }

    public function test_lost_session_reveal_is_refused_and_logged_with_its_reason(): void
    {
        $platform = Platform::factory()->create(['phone_prefix' => '254']);
        $target = $this->restrictedClient($platform, 9104);
        [$unlock, $token] = $this->unlock($platform, VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES);

        $body = [
            'public_token' => $token,
            'session_proof' => 'cookie-expired-'.Str::random(32),
            'target_wp_post_id' => $target->wp_post_id,
        ];
        $this->svc($platform, 'reveal', $body)->assertStatus(422);
        $this->svc($platform, 'reveal', $body)->assertStatus(422);

        $events = ContactUnlockEvent::query()->where('event_type', ContactUnlockEvent::TYPE_REVEAL_DENIED)->get();
        $this->assertCount(1, $events, 'Repeated refusals in the same hour are deduplicated.');
        $this->assertSame($unlock->id, (int) $events->first()->visitor_contact_unlock_id);
        $this->assertSame(ContactUnlockAccessService::DENIED_SESSION_MISMATCH, $events->first()->metadata_json['reason']);
    }

    public function test_market_pass_on_a_profile_the_crm_considers_active_logs_a_lifecycle_refusal(): void
    {
        $platform = Platform::factory()->create(['phone_prefix' => '254']);
        $target = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9105,
            'lifecycle_state' => ClientLifecycleState::ACTIVE,
        ]);
        [, $token, $session] = $this->unlock($platform, VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES);

        $this->svc($platform, 'reveal', [
            'public_token' => $token,
            'session_proof' => $session,
            'target_wp_post_id' => $target->wp_post_id,
        ])->assertStatus(422);

        $event = ContactUnlockEvent::query()->where('event_type', ContactUnlockEvent::TYPE_REVEAL_DENIED)->firstOrFail();
        $this->assertSame(ContactUnlockAccessService::DENIED_PROFILE_NOT_RESTRICTED, $event->metadata_json['reason']);
        $this->assertSame(ClientLifecycleState::ACTIVE, $event->metadata_json['target_lifecycle_state']);
    }

    private function restrictedClient(Platform $platform, int $wpPostId, string $phone = '254700000001'): Client
    {
        return Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'phone_normalized' => $phone,
            'lifecycle_state' => ClientLifecycleState::EXPIRED,
        ]);
    }

    private function unlock(Platform $platform, string $scope): array
    {
        $publicToken = 'public-'.Str::random(32);
        $sessionProof = 'session-'.Str::random(32);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
            'status' => 'completed',
            'amount' => 999,
            'currency' => 'KES',
            'completed_at' => now(),
        ]);

        $unlock = VisitorContactUnlock::query()->create([
            'platform_id' => $platform->id,
            'payment_id' => $payment->id,
            'scope' => $scope,
            'status' => VisitorContactUnlock::STATUS_ACTIVE,
            'gross_amount' => 999,
            'credit_amount' => 0,
            'amount_due' => 999,
            'visitor_phone_hash' => $this->tokenHash(self::PAYING_PHONE),
            'visitor_phone_masked' => '254******111',
            'idempotency_key_hash' => hash('sha256', Str::random(16)),
            'session_token_hash' => $this->tokenHash($sessionProof),
            'public_token_hash' => $this->tokenHash($publicToken),
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addDays(7),
        ]);

        return [$unlock, $publicToken, $sessionProof];
    }

    private function svc(Platform $platform, string $action, array $body)
    {
        $timestamp = time();

        return $this->postJson('/api/wp-svc/contact-unlock/'.$action, $body, [
            'X-Exotic-CRM-Sync-Key' => self::SHARED_KEY,
            'X-Exotic-Platform-Id' => (string) $platform->id,
            'X-Exotic-Timestamp' => (string) $timestamp,
            'X-Exotic-Signature' => hash_hmac('sha256', $timestamp.'.'.json_encode($body), self::SHARED_KEY),
        ]);
    }

    private function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
