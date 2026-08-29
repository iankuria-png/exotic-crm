<?php

namespace Tests\Feature\CustomerProduct;

use App\Models\Client;
use App\Models\CustomerAccount;
use App\Models\CustomerReachabilityFeedback;
use App\Models\CustomerSafetyReport;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Models\VisitorContactUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WpCustomerSafetyCentreTest extends TestCase
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

    public function test_member_report_is_recorded_and_appears_in_their_own_history(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 4501, 'name' => 'Sassie']);

        $body = $this->memberBody([
            'wp_post_id' => $client->wp_post_id,
            'category' => CustomerSafetyReport::CATEGORY_PHOTOS_NOT_REAL,
        ]);

        $response = $this->postJson('/api/wp-svc/customer/safety/report', $body, $this->signHeaders($platform->id, $body))
            ->assertCreated()
            ->assertJsonPath('report.category', CustomerSafetyReport::CATEGORY_PHOTOS_NOT_REAL)
            ->assertJsonPath('report.status', CustomerSafetyReport::STATUS_RECEIVED)
            ->assertJsonPath('report.profile.name', 'Sassie');

        $this->assertMatchesRegularExpression('/^SR-[0-9A-F]{8}$/', (string) $response->json('report.reference'));

        $this->assertDatabaseHas('customer_safety_reports', [
            'wp_post_id' => 4501,
            'client_id' => $client->id,
            'category' => CustomerSafetyReport::CATEGORY_PHOTOS_NOT_REAL,
            'source' => CustomerSafetyReport::SOURCE_MEMBER_PROFILE_REPORT,
        ]);

        $index = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/safety', $index, $this->signHeaders($platform->id, $index))
            ->assertOk()
            ->assertJsonPath('report_count', 1)
            ->assertJsonPath('reports.0.wp_post_id', 4501)
            ->assertJsonPath('reports.0.open', true);
    }

    public function test_another_member_cannot_read_the_first_members_history(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 4502]);

        $mine = $this->memberBody([
            'wp_post_id' => $client->wp_post_id,
            'category' => CustomerSafetyReport::CATEGORY_PAYMENT_SCAM,
        ]);
        $this->postJson('/api/wp-svc/customer/safety/report', $mine, $this->signHeaders($platform->id, $mine))->assertCreated();

        $theirs = $this->memberBody(['wp_user_id' => 9090, 'email' => 'other@example.com']);
        $this->postJson('/api/wp-svc/customer/safety', $theirs, $this->signHeaders($platform->id, $theirs))
            ->assertOk()
            ->assertJsonPath('report_count', 0)
            ->assertJsonPath('reports', []);
    }

    public function test_history_never_leaks_the_staff_review_note(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 4503]);

        $body = $this->memberBody([
            'wp_post_id' => $client->wp_post_id,
            'category' => CustomerSafetyReport::CATEGORY_ABUSIVE_BEHAVIOUR,
        ]);
        $this->postJson('/api/wp-svc/customer/safety/report', $body, $this->signHeaders($platform->id, $body))->assertCreated();

        CustomerSafetyReport::query()->update([
            'status' => CustomerSafetyReport::STATUS_UNDER_REVIEW,
            'review_note' => 'Staff only: advertiser contacted, awaiting ID.',
        ]);

        $index = $this->memberBody();
        $response = $this->postJson('/api/wp-svc/customer/safety', $index, $this->signHeaders($platform->id, $index))
            ->assertOk()
            ->assertJsonPath('reports.0.status', CustomerSafetyReport::STATUS_UNDER_REVIEW);

        $this->assertStringNotContainsString('Staff only', $response->getContent());
        $this->assertArrayNotHasKey('review_note', (array) $response->json('reports.0'));
        $this->assertArrayNotHasKey('reviewed_by', (array) $response->json('reports.0'));
    }

    public function test_repeat_report_of_the_same_profile_and_reason_does_not_stack(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 4504]);

        $body = $this->memberBody([
            'wp_post_id' => $client->wp_post_id,
            'category' => CustomerSafetyReport::CATEGORY_CONTACT_NOT_WORKING,
        ]);

        $first = $this->postJson('/api/wp-svc/customer/safety/report', $body, $this->signHeaders($platform->id, $body))->assertCreated();
        $second = $this->postJson('/api/wp-svc/customer/safety/report', $body, $this->signHeaders($platform->id, $body))->assertCreated();

        $this->assertSame($first->json('report.reference'), $second->json('report.reference'));
        $this->assertSame(1, CustomerSafetyReport::query()->count());
    }

    public function test_unknown_category_and_missing_profile_are_refused(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 4505]);

        $badCategory = $this->memberBody(['wp_post_id' => $client->wp_post_id, 'category' => 'she_ignored_me']);
        $this->postJson('/api/wp-svc/customer/safety/report', $badCategory, $this->signHeaders($platform->id, $badCategory))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Choose what the problem is.');

        $badProfile = $this->memberBody(['wp_post_id' => 0, 'category' => CustomerSafetyReport::CATEGORY_OTHER]);
        $this->postJson('/api/wp-svc/customer/safety/report', $badProfile, $this->signHeaders($platform->id, $badProfile))
            ->assertStatus(422);

        $this->assertSame(0, CustomerSafetyReport::query()->count());
    }

    public function test_daily_report_cap_is_enforced(): void
    {
        $platform = Platform::factory()->create();

        for ($i = 0; $i < CustomerSafetyReport::MAX_PER_DAY; $i++) {
            $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 4600 + $i]);
            $body = $this->memberBody([
                'wp_post_id' => $client->wp_post_id,
                'category' => CustomerSafetyReport::CATEGORY_OTHER,
            ]);
            $this->postJson('/api/wp-svc/customer/safety/report', $body, $this->signHeaders($platform->id, $body))->assertCreated();
        }

        $extra = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 4699]);
        $body = $this->memberBody([
            'wp_post_id' => $extra->wp_post_id,
            'category' => CustomerSafetyReport::CATEGORY_OTHER,
        ]);

        $this->postJson('/api/wp-svc/customer/safety/report', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(422);

        $this->assertSame(CustomerSafetyReport::MAX_PER_DAY, CustomerSafetyReport::query()->count());
    }

    public function test_a_non_member_role_and_an_unsigned_request_are_refused(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 4506]);

        $escort = $this->memberBody([
            'wp_role' => 'escort',
            'wp_post_id' => $client->wp_post_id,
            'category' => CustomerSafetyReport::CATEGORY_OTHER,
        ]);
        $this->postJson('/api/wp-svc/customer/safety/report', $escort, $this->signHeaders($platform->id, $escort))
            ->assertStatus(422);

        $unsigned = $this->memberBody([
            'wp_post_id' => $client->wp_post_id,
            'category' => CustomerSafetyReport::CATEGORY_OTHER,
        ]);
        $this->postJson('/api/wp-svc/customer/safety/report', $unsigned)->assertStatus(401);

        $this->assertSame(0, CustomerSafetyReport::query()->count());
    }

    public function test_reachability_history_shows_one_row_per_claimed_unlock_with_the_latest_outcome(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 4507,
            'name' => 'Reach Test',
            'phone_normalized' => '254711222333',
        ]);
        [, $publicToken, $sessionProof] = $this->unlock($platform, $client, ['last_revealed_at' => now()]);

        $claimBody = $this->memberBody([
            'public_token' => $publicToken,
            'session_proof' => $sessionProof,
            'target_wp_post_id' => $client->wp_post_id,
        ]);
        $claimId = (int) $this->postJson('/api/wp-svc/customer/unlocks/claim', $claimBody, $this->signHeaders($platform->id, $claimBody))
            ->assertOk()
            ->json('claim.id');

        foreach ([
            CustomerReachabilityFeedback::OUTCOME_NO_ANSWER,
            CustomerReachabilityFeedback::OUTCOME_WRONG_NUMBER,
        ] as $outcome) {
            $feedback = $this->memberBody(['claim_id' => $claimId, 'outcome' => $outcome]);
            $this->postJson('/api/wp-svc/customer/reachability', $feedback, $this->signHeaders($platform->id, $feedback))->assertCreated();
        }

        $index = $this->memberBody();
        $response = $this->postJson('/api/wp-svc/customer/safety', $index, $this->signHeaders($platform->id, $index))
            ->assertOk()
            ->assertJsonCount(1, 'reachability')
            ->assertJsonPath('reachability.0.claim_id', $claimId)
            ->assertJsonPath('reachability.0.outcome', CustomerReachabilityFeedback::OUTCOME_WRONG_NUMBER)
            ->assertJsonPath('reachability.0.submission_count', 2)
            ->assertJsonPath('reachability.0.profile.name', 'Reach Test');

        // Repeated negatives create an internal review signal, never a public
        // accusation and never automatic advertiser suppression.
        $this->assertStringNotContainsString('review_reason', $response->getContent());
        $this->assertDatabaseHas('customer_reachability_feedback', [
            'client_id' => $client->id,
            'status' => CustomerReachabilityFeedback::STATUS_PENDING_REVIEW,
        ]);
        $this->assertDatabaseHas('clients', ['id' => $client->id, 'platform_id' => $platform->id]);
    }

    public function test_deleting_the_member_anonymizes_reports_instead_of_orphaning_them(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 4508]);

        $body = $this->memberBody([
            'wp_post_id' => $client->wp_post_id,
            'category' => CustomerSafetyReport::CATEGORY_UNDERAGE_OR_COERCED,
        ]);
        $this->postJson('/api/wp-svc/customer/safety/report', $body, $this->signHeaders($platform->id, $body))->assertCreated();

        $forget = ['wp_user_id' => 4242];
        $this->postJson('/api/wp-svc/customer/forget', $forget, $this->signHeaders($platform->id, $forget))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, CustomerAccount::query()->count());
        $this->assertSame(1, CustomerSafetyReport::query()->whereNull('customer_account_id')->count());
    }

    public function test_retention_anonymizes_old_reports_and_leaves_recent_ones(): void
    {
        $platform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 4509]);

        $body = $this->memberBody([
            'wp_post_id' => $client->wp_post_id,
            'category' => CustomerSafetyReport::CATEGORY_DUPLICATE_OR_STOLEN,
        ]);
        $this->postJson('/api/wp-svc/customer/safety/report', $body, $this->signHeaders($platform->id, $body))->assertCreated();

        $account = CustomerAccount::query()->firstOrFail();
        CustomerSafetyReport::query()->create([
            'reference' => 'SR-DEADBEEF',
            'customer_account_id' => $account->id,
            'platform_id' => $platform->id,
            'wp_post_id' => 4510,
            'category' => CustomerSafetyReport::CATEGORY_OTHER,
            'status' => CustomerSafetyReport::STATUS_CLOSED,
            'source' => CustomerSafetyReport::SOURCE_MEMBER_PROFILE_REPORT,
            'submitted_at' => now()->subDays(CustomerSafetyReport::ANONYMIZE_AFTER_DAYS + 1),
        ]);

        $this->artisan('crm:purge-customer-data')->assertExitCode(0);

        $this->assertSame(1, CustomerSafetyReport::query()->whereNull('customer_account_id')->count());
        $this->assertSame(1, CustomerSafetyReport::query()->whereNotNull('customer_account_id')->count());
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
