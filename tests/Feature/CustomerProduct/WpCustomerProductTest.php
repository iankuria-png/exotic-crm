<?php

namespace Tests\Feature\CustomerProduct;

use App\Models\CustomerAccount;
use App\Models\CustomerActivityEvent;
use App\Models\CustomerSavedObject;
use App\Models\Platform;
use App\Models\User;
use App\Services\CustomerProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WpCustomerProductTest extends TestCase
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

    // ---------------------------------------------------------------- identity

    public function test_sync_links_a_member_account_and_returns_an_empty_workspace(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody();

        $this->postJson('/api/wp-svc/customer/sync', $body, $this->signHeaders($platform->id, $body))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('saved_count', 0)
            ->assertJsonPath('saved_profile_ids', []);

        $account = CustomerAccount::query()->firstOrFail();
        $this->assertSame($platform->id, (int) $account->platform_id);
        $this->assertSame(4242, (int) $account->wp_user_id);
        $this->assertSame('Jay', $account->display_name);
        $this->assertSame('jay@example.com', $account->email);
        $this->assertSame(hash('sha256', 'jay@example.com'), $account->email_hash);

        $this->assertDatabaseHas('customer_activity_events', [
            'customer_account_id' => $account->id,
            'event_type' => CustomerActivityEvent::EVENT_ACCOUNT_LINKED,
        ]);
    }

    public function test_sync_is_idempotent_and_refreshes_name_and_email_from_wordpress(): void
    {
        $platform = Platform::factory()->create();

        $first = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/sync', $first, $this->signHeaders($platform->id, $first))->assertOk();

        $second = $this->memberBody(['display_name' => 'Jay Renamed', 'email' => 'new@example.com']);
        $this->postJson('/api/wp-svc/customer/sync', $second, $this->signHeaders($platform->id, $second))->assertOk();

        $this->assertSame(1, CustomerAccount::query()->count());

        $account = CustomerAccount::query()->firstOrFail();
        $this->assertSame('Jay Renamed', $account->display_name);
        $this->assertSame('new@example.com', $account->email);
        $this->assertSame(hash('sha256', 'new@example.com'), $account->email_hash);
    }

    public function test_a_non_member_wordpress_role_is_rejected(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody(['wp_role' => 'escort']);

        $this->postJson('/api/wp-svc/customer/sync', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(422);

        $this->assertSame(0, CustomerAccount::query()->count());
    }

    public function test_a_missing_wp_user_id_is_rejected(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody(['wp_user_id' => 0]);

        $this->postJson('/api/wp-svc/customer/sync', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(422);

        $this->assertSame(0, CustomerAccount::query()->count());
    }

    public function test_an_unsigned_request_is_rejected(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody();

        $this->postJson('/api/wp-svc/customer/sync', $body)->assertStatus(401);
        $this->assertSame(0, CustomerAccount::query()->count());
    }

    public function test_a_tampered_body_is_rejected(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody();
        $headers = $this->signHeaders($platform->id, $body);

        $tampered = $this->memberBody(['wp_user_id' => 9999]);

        $this->postJson('/api/wp-svc/customer/sync', $tampered, $headers)->assertStatus(401);
        $this->assertSame(0, CustomerAccount::query()->count());
    }

    public function test_two_platforms_do_not_share_a_customer_with_the_same_wp_user_id(): void
    {
        $kenya = Platform::factory()->create();
        $uganda = Platform::factory()->create();
        $body = $this->memberBody();

        $this->postJson('/api/wp-svc/customer/sync', $body, $this->signHeaders($kenya->id, $body))->assertOk();
        $this->postJson('/api/wp-svc/customer/sync', $body, $this->signHeaders($uganda->id, $body))->assertOk();

        $this->assertSame(2, CustomerAccount::query()->count());
    }

    // ------------------------------------------------------------------- saves

    public function test_a_member_can_save_and_unsave_a_profile(): void
    {
        $platform = Platform::factory()->create();

        $add = $this->memberBody(['object_ref' => 5150]);
        $this->postJson('/api/wp-svc/customer/saved/add', $add, $this->signHeaders($platform->id, $add))
            ->assertStatus(201)
            ->assertJsonPath('created', true)
            ->assertJsonPath('saved_count', 1)
            ->assertJsonPath('saved_profile_ids', [5150]);

        // Saving twice is idempotent, not an error.
        $this->postJson('/api/wp-svc/customer/saved/add', $add, $this->signHeaders($platform->id, $add))
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('saved_count', 1);

        $remove = $this->memberBody(['object_ref' => 5150]);
        $this->postJson('/api/wp-svc/customer/saved/remove', $remove, $this->signHeaders($platform->id, $remove))
            ->assertOk()
            ->assertJsonPath('removed', true)
            ->assertJsonPath('saved_count', 0);

        $this->assertSame(0, CustomerSavedObject::query()->count());
    }

    public function test_removing_the_first_saved_profile_works(): void
    {
        // The legacy WordPress favourites flow had a first-item removal bug.
        // The CRM contract must not reproduce it.
        $platform = Platform::factory()->create();

        foreach ([101, 202, 303] as $ref) {
            $body = $this->memberBody(['object_ref' => $ref]);
            $this->postJson('/api/wp-svc/customer/saved/add', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $remove = $this->memberBody(['object_ref' => 101]);
        $this->postJson('/api/wp-svc/customer/saved/remove', $remove, $this->signHeaders($platform->id, $remove))
            ->assertOk()
            ->assertJsonPath('removed', true)
            ->assertJsonPath('saved_count', 2);

        $remaining = CustomerSavedObject::query()->pluck('object_ref')->map(fn ($r) => (int) $r)->all();
        sort($remaining);
        $this->assertSame([202, 303], $remaining);
    }

    public function test_one_member_cannot_see_another_members_saves(): void
    {
        $platform = Platform::factory()->create();

        $mine = $this->memberBody(['object_ref' => 777]);
        $this->postJson('/api/wp-svc/customer/saved/add', $mine, $this->signHeaders($platform->id, $mine))
            ->assertStatus(201);

        $theirs = $this->memberBody(['wp_user_id' => 9001, 'email' => 'other@example.com']);
        $this->postJson('/api/wp-svc/customer/saved', $theirs, $this->signHeaders($platform->id, $theirs))
            ->assertOk()
            ->assertJsonPath('saved_count', 0)
            ->assertJsonPath('saved_profile_ids', []);
    }

    public function test_saves_are_capped(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/sync', $body, $this->signHeaders($platform->id, $body))->assertOk();
        $account = CustomerAccount::query()->firstOrFail();

        $now = Carbon::now();
        $rows = [];
        for ($i = 1; $i <= CustomerProductService::MAX_SAVED_OBJECTS; $i++) {
            $rows[] = [
                'customer_account_id' => $account->id,
                'platform_id' => $platform->id,
                'object_type' => CustomerSavedObject::TYPE_PROFILE,
                'object_ref' => 100000 + $i,
                'source' => CustomerSavedObject::SOURCE_WORKSPACE,
                'saved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        CustomerSavedObject::query()->insert($rows);

        $overflow = $this->memberBody(['object_ref' => 999999]);
        $this->postJson('/api/wp-svc/customer/saved/add', $overflow, $this->signHeaders($platform->id, $overflow))
            ->assertStatus(422);

        $this->assertSame(CustomerProductService::MAX_SAVED_OBJECTS, CustomerSavedObject::query()->count());
    }

    // ------------------------------------------------------------------- merge

    public function test_merge_folds_a_batch_in_without_duplicating(): void
    {
        $platform = Platform::factory()->create();

        $seed = $this->memberBody(['object_ref' => 11]);
        $this->postJson('/api/wp-svc/customer/saved/add', $seed, $this->signHeaders($platform->id, $seed))
            ->assertStatus(201);

        $merge = $this->memberBody([
            'object_refs' => [11, 22, 33, 22, 0, -5],
            'source' => CustomerSavedObject::SOURCE_LEGACY_BACKFILL,
        ]);

        $this->postJson('/api/wp-svc/customer/saved/merge', $merge, $this->signHeaders($platform->id, $merge))
            ->assertOk()
            ->assertJsonPath('merged', 2)
            ->assertJsonPath('saved_count', 3);

        $refs = CustomerSavedObject::query()->pluck('object_ref')->map(fn ($r) => (int) $r)->all();
        sort($refs);
        $this->assertSame([11, 22, 33], $refs);
    }

    // -------------------------------------------------------- delete + retention

    public function test_forget_removes_the_account_and_everything_under_it(): void
    {
        $platform = Platform::factory()->create();

        $save = $this->memberBody(['object_ref' => 4321]);
        $this->postJson('/api/wp-svc/customer/saved/add', $save, $this->signHeaders($platform->id, $save))
            ->assertStatus(201);

        $this->assertSame(1, CustomerAccount::query()->count());
        $this->assertSame(1, CustomerSavedObject::query()->count());
        $this->assertGreaterThan(0, CustomerActivityEvent::query()->count());

        $forget = ['wp_user_id' => 4242];
        $this->postJson('/api/wp-svc/customer/forget', $forget, $this->signHeaders($platform->id, $forget))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, CustomerAccount::query()->count());
        $this->assertSame(0, CustomerSavedObject::query()->count());
        $this->assertSame(0, CustomerActivityEvent::query()->count());
    }

    public function test_forget_is_safe_for_an_unknown_customer(): void
    {
        $platform = Platform::factory()->create();
        $forget = ['wp_user_id' => 123456];

        $this->postJson('/api/wp-svc/customer/forget', $forget, $this->signHeaders($platform->id, $forget))
            ->assertOk()
            ->assertJsonPath('deleted', false);
    }

    public function test_retention_purges_activity_events_past_180_days(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/sync', $body, $this->signHeaders($platform->id, $body))->assertOk();
        $account = CustomerAccount::query()->firstOrFail();

        CustomerActivityEvent::query()->create([
            'customer_account_id' => $account->id,
            'platform_id' => $platform->id,
            'event_type' => CustomerActivityEvent::EVENT_WORKSPACE_VIEWED,
            'occurred_at' => Carbon::now()->subDays(CustomerActivityEvent::RETENTION_DAYS + 1),
        ]);
        CustomerActivityEvent::query()->create([
            'customer_account_id' => $account->id,
            'platform_id' => $platform->id,
            'event_type' => CustomerActivityEvent::EVENT_WORKSPACE_VIEWED,
            'occurred_at' => Carbon::now()->subDays(CustomerActivityEvent::RETENTION_DAYS - 1),
        ]);

        $before = CustomerActivityEvent::query()->count();
        $this->artisan('crm:purge-customer-data')->assertExitCode(0);
        $after = CustomerActivityEvent::query()->count();

        $this->assertSame($before - 1, $after);
        $this->assertSame(0, CustomerActivityEvent::query()
            ->where('occurred_at', '<', Carbon::now()->subDays(CustomerActivityEvent::RETENTION_DAYS))
            ->count());
    }

    // ------------------------------------------------------------------ helpers

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
