<?php

namespace Tests\Feature\CustomerProduct;

use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\CustomerReachabilityFeedback;
use App\Models\CustomerUnlockClaim;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Models\VisitorContactUnlock;
use App\Services\CustomerProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WpCustomerUnlockClaimsTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_KEY = 'customer-product-shared-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exotic_crm_sync.shared_key' => self::SHARED_KEY,
            'services.wp_service_auth.platform_allowlist' => [],
        ]);

        User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_member_claims_a_just_revealed_unlock_and_sees_it_in_unlocked_contacts(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'KES']);
        $client = Client::factory()->create(['platform_id' => $platform->id, 'name' => 'Sassie', 'wp_post_id' => 5150]);
        [$unlock, $publicToken, $sessionProof] = $this->unlock($platform, $client, ['last_revealed_at' => now()]);

        $claimBody = $this->memberBody([
            'public_token' => $publicToken,
            'session_proof' => $sessionProof,
            'target_wp_post_id' => $client->wp_post_id,
            'source' => CustomerUnlockClaim::SOURCE_POST_UNLOCK_ACCOUNT,
        ]);

        $this->postJson('/api/wp-svc/customer/unlocks/claim', $claimBody, $this->signHeaders($platform->id, $claimBody))
            ->assertOk()
            ->assertJsonPath('claimed', true)
            ->assertJsonPath('unlocked_count', 1)
            ->assertJsonPath('claim.wp_post_id', 5150)
            ->assertJsonPath('claim.profile.name', 'Sassie');

        $this->assertDatabaseHas('customer_unlock_claims', [
            'visitor_contact_unlock_id' => $unlock->id,
            'wp_post_id' => 5150,
            'source' => CustomerUnlockClaim::SOURCE_POST_UNLOCK_ACCOUNT,
        ]);

        $index = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/unlocks', $index, $this->signHeaders($platform->id, $index))
            ->assertOk()
            ->assertJsonPath('unlocks.0.wp_post_id', 5150)
            ->assertJsonMissingPath('unlocks.0.contact');
    }

    public function test_another_member_cannot_claim_the_same_unlock(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 6161]);
        [$unlock, $publicToken, $sessionProof] = $this->unlock($platform, $client, ['last_revealed_at' => now()]);

        $first = $this->memberBody([
            'public_token' => $publicToken,
            'session_proof' => $sessionProof,
            'target_wp_post_id' => $client->wp_post_id,
        ]);
        $this->postJson('/api/wp-svc/customer/unlocks/claim', $first, $this->signHeaders($platform->id, $first))->assertOk();

        $second = $this->memberBody([
            'wp_user_id' => 999,
            'email' => 'other@example.com',
            'public_token' => $publicToken,
            'session_proof' => $sessionProof,
            'target_wp_post_id' => $client->wp_post_id,
        ]);
        $this->postJson('/api/wp-svc/customer/unlocks/claim', $second, $this->signHeaders($platform->id, $second))
            ->assertStatus(422);

        $this->assertSame(1, CustomerUnlockClaim::query()->where('visitor_contact_unlock_id', $unlock->id)->count());
    }

    public function test_unrevealed_or_stale_anonymous_unlock_cannot_be_claimed_later(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 7171]);
        [, $publicToken, $sessionProof] = $this->unlock($platform, $client, [
            'last_revealed_at' => now()->subMinutes(CustomerProductService::UNLOCK_CLAIM_HANDOFF_MINUTES + 1),
        ]);

        $body = $this->memberBody([
            'public_token' => $publicToken,
            'session_proof' => $sessionProof,
            'target_wp_post_id' => $client->wp_post_id,
        ]);

        $this->postJson('/api/wp-svc/customer/unlocks/claim', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(422)
            ->assertJsonPath('message', "This one's not linked to your account.");

        $this->assertSame(0, CustomerUnlockClaim::query()->count());
    }

    public function test_reveal_and_reachability_feedback_require_the_rightful_claim(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 8181,
            'phone_normalized' => '254711222333',
        ]);
        [, $publicToken, $sessionProof] = $this->unlock($platform, $client, ['last_revealed_at' => now()]);

        $claimBody = $this->memberBody([
            'public_token' => $publicToken,
            'session_proof' => $sessionProof,
            'target_wp_post_id' => $client->wp_post_id,
        ]);
        $claimResponse = $this->postJson('/api/wp-svc/customer/unlocks/claim', $claimBody, $this->signHeaders($platform->id, $claimBody))->assertOk();
        $claimId = (int) $claimResponse->json('claim.id');

        $other = $this->memberBody(['wp_user_id' => 123, 'email' => 'other@example.com', 'claim_id' => $claimId]);
        $this->postJson('/api/wp-svc/customer/unlocks/reveal', $other, $this->signHeaders($platform->id, $other))
            ->assertStatus(422);

        $mine = $this->memberBody(['claim_id' => $claimId]);
        $this->postJson('/api/wp-svc/customer/unlocks/reveal', $mine, $this->signHeaders($platform->id, $mine))
            ->assertOk()
            ->assertJsonPath('contact.phone', '254711222333');

        $firstFeedback = $this->memberBody(['claim_id' => $claimId, 'outcome' => CustomerReachabilityFeedback::OUTCOME_NO_ANSWER]);
        $this->postJson('/api/wp-svc/customer/reachability', $firstFeedback, $this->signHeaders($platform->id, $firstFeedback))
            ->assertCreated()
            ->assertJsonPath('status', CustomerReachabilityFeedback::STATUS_RECORDED);

        $secondFeedback = $this->memberBody(['claim_id' => $claimId, 'outcome' => CustomerReachabilityFeedback::OUTCOME_WRONG_NUMBER]);
        $this->postJson('/api/wp-svc/customer/reachability', $secondFeedback, $this->signHeaders($platform->id, $secondFeedback))
            ->assertCreated()
            ->assertJsonPath('status', CustomerReachabilityFeedback::STATUS_PENDING_REVIEW);

        $this->assertDatabaseHas('customer_reachability_feedback', [
            'client_id' => $client->id,
            'status' => CustomerReachabilityFeedback::STATUS_PENDING_REVIEW,
            'review_reason' => CustomerReachabilityFeedback::REVIEW_REPEATED_NEGATIVE,
        ]);
    }

    public function test_staff_unlock_trail_exposes_claim_and_reachability_review_signals(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 9191, 'name' => 'Reach Test']);
        [, $publicToken, $sessionProof] = $this->unlock($platform, $client, ['last_revealed_at' => now()]);

        $claimBody = $this->memberBody([
            'public_token' => $publicToken,
            'session_proof' => $sessionProof,
            'target_wp_post_id' => $client->wp_post_id,
        ]);
        $claimResponse = $this->postJson('/api/wp-svc/customer/unlocks/claim', $claimBody, $this->signHeaders($platform->id, $claimBody))->assertOk();
        $claimId = (int) $claimResponse->json('claim.id');

        $first = $this->memberBody(['claim_id' => $claimId, 'outcome' => CustomerReachabilityFeedback::OUTCOME_NO_ANSWER]);
        $this->postJson('/api/wp-svc/customer/reachability', $first, $this->signHeaders($platform->id, $first))->assertCreated();
        $second = $this->memberBody(['claim_id' => $claimId, 'outcome' => CustomerReachabilityFeedback::OUTCOME_WRONG_NUMBER]);
        $this->postJson('/api/wp-svc/customer/reachability', $second, $this->signHeaders($platform->id, $second))->assertCreated();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active', 'assigned_market_ids' => []]));

        $this->getJson('/api/crm/settings/billing/contact-unlock?platform_id='.$platform->id)
            ->assertOk()
            ->assertJsonPath('recent_unlocks.0.claim_review.claimed', true)
            ->assertJsonPath('recent_unlocks.0.claim_review.latest_customer.name', 'Jay')
            ->assertJsonPath('recent_unlocks.0.claim_review.pending_reachability_reviews', 1)
            ->assertJsonPath('recent_unlocks.0.claim_review.latest_reachability_outcome', CustomerReachabilityFeedback::OUTCOME_WRONG_NUMBER);
    }

    private function unlock(Platform $platform, Client $client, array $overrides = []): array
    {
        $publicToken = 'public-'.Str::random(32);
        $sessionProof = 'session-'.Str::random(32);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
            'status' => 'completed',
            'amount' => 299,
            'currency' => 'KES',
            'completed_at' => now(),
        ]);

        $unlock = VisitorContactUnlock::query()->create(array_merge([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'wp_post_id' => $client->wp_post_id,
            'payment_id' => $payment->id,
            'scope' => VisitorContactUnlock::SCOPE_SINGLE_PROFILE,
            'status' => VisitorContactUnlock::STATUS_ACTIVE,
            'gross_amount' => 299,
            'credit_amount' => 0,
            'amount_due' => 299,
            'visitor_phone_hash' => hash('sha256', Str::random(16)),
            'visitor_phone_masked' => '254*****333',
            'idempotency_key_hash' => hash('sha256', Str::random(16)),
            'session_token_hash' => $this->tokenHash($sessionProof),
            'public_token_hash' => $this->tokenHash($publicToken),
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addDay(),
            'last_revealed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return [$unlock, $publicToken, $sessionProof];
    }

    private function tokenHash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    private function memberBody(array $overrides = []): array
    {
        return array_merge([
            'wp_user_id' => 4242,
            'wp_role' => 'member',
            'display_name' => 'Jay',
            'email' => 'jay@example.com',
        ], $overrides);
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
