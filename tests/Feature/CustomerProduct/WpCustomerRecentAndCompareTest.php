<?php

namespace Tests\Feature\CustomerProduct;

use App\Models\CustomerAccount;
use App\Models\CustomerActivityEvent;
use App\Models\CustomerCompareItem;
use App\Models\CustomerCompareSet;
use App\Models\CustomerRecentView;
use App\Models\Platform;
use App\Models\User;
use App\Services\CustomerProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Phase 3: CRM-backed recent views and the compare tray.
 *
 * Mirrors the permission and identity coverage in WpCustomerProductTest — the
 * new routes must not be a softer door into the same tables.
 */
class WpCustomerRecentAndCompareTest extends TestCase
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

    // ------------------------------------------------------------ recent views

    public function test_a_member_view_is_recorded_and_re_viewing_bumps_instead_of_appending(): void
    {
        $platform = Platform::factory()->create();

        $body = $this->memberBody(['object_ref' => 8801]);

        // The hottest endpoint returns a minimal body on purpose - the caller
        // is fire-and-forget, so building the full summary would cost three
        // extra queries per profile view for a payload nobody reads.
        $this->postJson('/api/wp-svc/customer/recent/record', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(201)
            ->assertJsonPath('created', true)
            ->assertJsonMissingPath('saved_profile_ids');

        $this->postJson('/api/wp-svc/customer/recent/record', $body, $this->signHeaders($platform->id, $body))
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertSame(1, CustomerRecentView::query()->count());
        $this->assertSame(2, (int) CustomerRecentView::query()->firstOrFail()->view_count);
    }

    public function test_recent_views_come_back_newest_first(): void
    {
        // No clock travel: every view lands inside the same second, which is
        // exactly the case `last_viewed_at` alone cannot order. The monotonic
        // view counter has to carry it.
        $platform = Platform::factory()->create();

        foreach ([11, 22, 33] as $ref) {
            $body = $this->memberBody(['object_ref' => $ref]);
            $this->postJson('/api/wp-svc/customer/recent/record', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $read = $this->memberBody();
        $response = $this->postJson('/api/wp-svc/customer/recent', $read, $this->signHeaders($platform->id, $read))
            ->assertOk()
            ->assertJsonPath('recent_count', 3);

        $refs = array_column($response->json('recent_views'), 'object_ref');
        $this->assertSame([33, 22, 11], $refs);
    }

    /**
     * The service caches the compare set, its items, and the recent-view count
     * for the length of one request, and the container hands out the same
     * service instance across requests. This walks the exact sequence that
     * caught a stale memo: a full tray, a removal, then a refill that must be
     * accepted, and a saved-list read that must reflect a write from the
     * request before it.
     */
    public function test_per_request_caches_do_not_leak_between_requests(): void
    {
        $platform = Platform::factory()->create();

        foreach ([701, 702, 703, 704] as $ref) {
            $body = $this->memberBody(['object_ref' => $ref]);
            $this->postJson('/api/wp-svc/customer/compare/add', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $remove = $this->memberBody(['object_ref' => 702]);
        $this->postJson('/api/wp-svc/customer/compare/remove', $remove, $this->signHeaders($platform->id, $remove))
            ->assertOk()
            ->assertJsonPath('removed', true);

        $refill = $this->memberBody(['object_ref' => 705]);
        $this->postJson('/api/wp-svc/customer/compare/add', $refill, $this->signHeaders($platform->id, $refill))
            ->assertStatus(201)
            ->assertJsonPath('compare_profile_ids', [701, 703, 704, 705]);

        // A saved write in one request must be visible to the read in the next.
        $save = $this->memberBody(['object_ref' => 801]);
        $this->postJson('/api/wp-svc/customer/saved/add', $save, $this->signHeaders($platform->id, $save))
            ->assertStatus(201);

        $read = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/saved', $read, $this->signHeaders($platform->id, $read))
            ->assertOk()
            ->assertJsonPath('saved_profile_ids', [801]);

        // And a cleared history must not come back from a cached count.
        $view = $this->memberBody(['object_ref' => 901]);
        $this->postJson('/api/wp-svc/customer/recent/record', $view, $this->signHeaders($platform->id, $view))
            ->assertStatus(201);

        $clear = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/recent/clear', $clear, $this->signHeaders($platform->id, $clear))
            ->assertOk()
            ->assertJsonPath('recent_count', 0);

        $after = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/recent', $after, $this->signHeaders($platform->id, $after))
            ->assertOk()
            ->assertJsonPath('recent_count', 0)
            ->assertJsonPath('recent_views', []);
    }

    public function test_re_viewing_a_profile_moves_it_back_to_the_top(): void
    {
        $platform = Platform::factory()->create();

        foreach ([11, 22, 33] as $ref) {
            $body = $this->memberBody(['object_ref' => $ref]);
            $this->postJson('/api/wp-svc/customer/recent/record', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $again = $this->memberBody(['object_ref' => 11]);
        $this->postJson('/api/wp-svc/customer/recent/record', $again, $this->signHeaders($platform->id, $again))
            ->assertOk()
            ->assertJsonPath('created', false);

        $read = $this->memberBody();
        $response = $this->postJson('/api/wp-svc/customer/recent', $read, $this->signHeaders($platform->id, $read))
            ->assertOk();

        $refs = array_column($response->json('recent_views'), 'object_ref');
        $this->assertSame([11, 33, 22], $refs);
    }

    public function test_recent_views_can_be_cleared(): void
    {
        $platform = Platform::factory()->create();

        foreach ([44, 55] as $ref) {
            $body = $this->memberBody(['object_ref' => $ref]);
            $this->postJson('/api/wp-svc/customer/recent/record', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $clear = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/recent/clear', $clear, $this->signHeaders($platform->id, $clear))
            ->assertOk()
            ->assertJsonPath('cleared', 2)
            ->assertJsonPath('recent_count', 0)
            ->assertJsonPath('recent_views', []);

        $this->assertSame(0, CustomerRecentView::query()->count());

        $account = CustomerAccount::query()->firstOrFail();
        $this->assertDatabaseHas('customer_activity_events', [
            'customer_account_id' => $account->id,
            'event_type' => CustomerActivityEvent::EVENT_VIEWS_CLEARED,
        ]);
    }

    public function test_recent_views_are_trimmed_to_the_per_account_cap(): void
    {
        $platform = Platform::factory()->create();
        $seed = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/sync', $seed, $this->signHeaders($platform->id, $seed))->assertOk();
        $account = CustomerAccount::query()->firstOrFail();

        $now = Carbon::now();
        $rows = [];
        for ($i = 1; $i <= CustomerRecentView::MAX_PER_ACCOUNT; $i++) {
            $rows[] = [
                'customer_account_id' => $account->id,
                'platform_id' => $platform->id,
                'object_type' => CustomerRecentView::TYPE_PROFILE,
                'object_ref' => 500000 + $i,
                'view_count' => 1,
                'view_seq' => $i,
                // Ascending, so 500001 is the oldest and should be the one trimmed.
                'first_viewed_at' => (clone $now)->subMinutes(CustomerRecentView::MAX_PER_ACCOUNT - $i),
                'last_viewed_at' => (clone $now)->subMinutes(CustomerRecentView::MAX_PER_ACCOUNT - $i),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        CustomerRecentView::query()->insert($rows);

        $fresh = $this->memberBody(['object_ref' => 999111]);
        $this->postJson('/api/wp-svc/customer/recent/record', $fresh, $this->signHeaders($platform->id, $fresh))
            ->assertStatus(201);

        $this->assertSame(CustomerRecentView::MAX_PER_ACCOUNT, CustomerRecentView::query()->count());
        $this->assertSame(0, CustomerRecentView::query()->where('object_ref', 500001)->count());
        $this->assertSame(1, CustomerRecentView::query()->where('object_ref', 999111)->count());
    }

    public function test_one_member_cannot_see_another_members_recent_views(): void
    {
        $platform = Platform::factory()->create();

        $mine = $this->memberBody(['object_ref' => 606]);
        $this->postJson('/api/wp-svc/customer/recent/record', $mine, $this->signHeaders($platform->id, $mine))
            ->assertStatus(201);

        $theirs = $this->memberBody(['wp_user_id' => 9001, 'email' => 'other@example.com']);
        $this->postJson('/api/wp-svc/customer/recent', $theirs, $this->signHeaders($platform->id, $theirs))
            ->assertOk()
            ->assertJsonPath('recent_count', 0)
            ->assertJsonPath('recent_views', []);
    }

    // ----------------------------------------------------------------- compare

    public function test_a_member_can_add_and_remove_compare_profiles(): void
    {
        $platform = Platform::factory()->create();

        $add = $this->memberBody(['object_ref' => 7001]);
        $this->postJson('/api/wp-svc/customer/compare/add', $add, $this->signHeaders($platform->id, $add))
            ->assertStatus(201)
            ->assertJsonPath('created', true)
            ->assertJsonPath('compare_count', 1)
            ->assertJsonPath('compare_profile_ids', [7001])
            ->assertJsonPath('compare_limit', CustomerProductService::MAX_COMPARE_ITEMS);

        // Adding twice is idempotent, not an error.
        $this->postJson('/api/wp-svc/customer/compare/add', $add, $this->signHeaders($platform->id, $add))
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('compare_count', 1);

        $remove = $this->memberBody(['object_ref' => 7001]);
        $this->postJson('/api/wp-svc/customer/compare/remove', $remove, $this->signHeaders($platform->id, $remove))
            ->assertOk()
            ->assertJsonPath('removed', true)
            ->assertJsonPath('compare_count', 0);

        $this->assertSame(0, CustomerCompareItem::query()->count());
    }

    public function test_compare_rejects_a_fifth_profile(): void
    {
        $platform = Platform::factory()->create();

        foreach ([1, 2, 3, 4] as $ref) {
            $body = $this->memberBody(['object_ref' => 900 + $ref]);
            $this->postJson('/api/wp-svc/customer/compare/add', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $fifth = $this->memberBody(['object_ref' => 905]);
        $this->postJson('/api/wp-svc/customer/compare/add', $fifth, $this->signHeaders($platform->id, $fifth))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Compare holds four. Remove one first.');

        $this->assertSame(4, CustomerCompareItem::query()->count());
        $this->assertSame(0, CustomerCompareItem::query()->where('object_ref', 905)->count());
    }

    public function test_removing_the_first_compare_profile_works(): void
    {
        // Same class of bug as the legacy favourites first-item removal.
        $platform = Platform::factory()->create();

        foreach ([301, 302, 303] as $ref) {
            $body = $this->memberBody(['object_ref' => $ref]);
            $this->postJson('/api/wp-svc/customer/compare/add', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $remove = $this->memberBody(['object_ref' => 301]);
        $this->postJson('/api/wp-svc/customer/compare/remove', $remove, $this->signHeaders($platform->id, $remove))
            ->assertOk()
            ->assertJsonPath('removed', true)
            ->assertJsonPath('compare_profile_ids', [302, 303]);
    }

    public function test_a_freed_slot_can_be_refilled(): void
    {
        $platform = Platform::factory()->create();

        foreach ([401, 402, 403, 404] as $ref) {
            $body = $this->memberBody(['object_ref' => $ref]);
            $this->postJson('/api/wp-svc/customer/compare/add', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $remove = $this->memberBody(['object_ref' => 402]);
        $this->postJson('/api/wp-svc/customer/compare/remove', $remove, $this->signHeaders($platform->id, $remove))
            ->assertOk();

        $refill = $this->memberBody(['object_ref' => 405]);
        $this->postJson('/api/wp-svc/customer/compare/add', $refill, $this->signHeaders($platform->id, $refill))
            ->assertStatus(201)
            ->assertJsonPath('compare_count', 4)
            ->assertJsonPath('compare_profile_ids', [401, 403, 404, 405]);
    }

    public function test_compare_can_be_cleared_and_the_set_header_survives(): void
    {
        $platform = Platform::factory()->create();

        foreach ([501, 502] as $ref) {
            $body = $this->memberBody(['object_ref' => $ref]);
            $this->postJson('/api/wp-svc/customer/compare/add', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $clear = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/compare/clear', $clear, $this->signHeaders($platform->id, $clear))
            ->assertOk()
            ->assertJsonPath('cleared', 2)
            ->assertJsonPath('compare_count', 0);

        $this->assertSame(0, CustomerCompareItem::query()->count());
        // The header stays: it carries the timestamp retention is measured from.
        $this->assertSame(1, CustomerCompareSet::query()->count());
    }

    public function test_one_member_cannot_see_another_members_compare_tray(): void
    {
        $platform = Platform::factory()->create();

        $mine = $this->memberBody(['object_ref' => 808]);
        $this->postJson('/api/wp-svc/customer/compare/add', $mine, $this->signHeaders($platform->id, $mine))
            ->assertStatus(201);

        $theirs = $this->memberBody(['wp_user_id' => 9001, 'email' => 'other@example.com']);
        $this->postJson('/api/wp-svc/customer/compare', $theirs, $this->signHeaders($platform->id, $theirs))
            ->assertOk()
            ->assertJsonPath('compare_count', 0)
            ->assertJsonPath('compare_profile_ids', []);
    }

    // -------------------------------------------------------------- permissions

    public function test_the_new_routes_reject_a_non_member_role(): void
    {
        $platform = Platform::factory()->create();

        foreach (['recent/record', 'recent/clear', 'compare/add', 'compare/remove', 'compare/clear'] as $route) {
            $body = $this->memberBody(['wp_role' => 'escort', 'object_ref' => 123]);
            $this->postJson('/api/wp-svc/customer/'.$route, $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(422);
        }

        $this->assertSame(0, CustomerAccount::query()->count());
        $this->assertSame(0, CustomerRecentView::query()->count());
        $this->assertSame(0, CustomerCompareItem::query()->count());
    }

    public function test_the_new_routes_reject_an_unsigned_request(): void
    {
        Platform::factory()->create();

        foreach (['recent', 'recent/record', 'recent/clear', 'compare', 'compare/add', 'compare/remove', 'compare/clear'] as $route) {
            $this->postJson('/api/wp-svc/customer/'.$route, $this->memberBody(['object_ref' => 123]))
                ->assertStatus(401);
        }

        $this->assertSame(0, CustomerAccount::query()->count());
    }

    public function test_the_new_routes_reject_a_tampered_body(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody(['object_ref' => 123]);
        $headers = $this->signHeaders($platform->id, $body);

        $tampered = $this->memberBody(['object_ref' => 456]);

        $this->postJson('/api/wp-svc/customer/compare/add', $tampered, $headers)->assertStatus(401);
        $this->assertSame(0, CustomerCompareItem::query()->count());
    }

    public function test_two_platforms_do_not_share_recent_views_or_compare(): void
    {
        $kenya = Platform::factory()->create();
        $uganda = Platform::factory()->create();

        $body = $this->memberBody(['object_ref' => 6001]);
        $this->postJson('/api/wp-svc/customer/compare/add', $body, $this->signHeaders($kenya->id, $body))
            ->assertStatus(201);

        $this->postJson('/api/wp-svc/customer/compare', $body, $this->signHeaders($uganda->id, $body))
            ->assertOk()
            ->assertJsonPath('compare_count', 0);

        $this->assertSame(2, CustomerAccount::query()->count());
    }

    // ------------------------------------------------------- delete + retention

    public function test_forget_removes_recent_views_and_compare_too(): void
    {
        $platform = Platform::factory()->create();

        $view = $this->memberBody(['object_ref' => 7100]);
        $this->postJson('/api/wp-svc/customer/recent/record', $view, $this->signHeaders($platform->id, $view))
            ->assertStatus(201);

        $compare = $this->memberBody(['object_ref' => 7200]);
        $this->postJson('/api/wp-svc/customer/compare/add', $compare, $this->signHeaders($platform->id, $compare))
            ->assertStatus(201);

        $forget = ['wp_user_id' => 4242];
        $this->postJson('/api/wp-svc/customer/forget', $forget, $this->signHeaders($platform->id, $forget))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, CustomerAccount::query()->count());
        $this->assertSame(0, CustomerRecentView::query()->count());
        $this->assertSame(0, CustomerCompareItem::query()->count());
        $this->assertSame(0, CustomerCompareSet::query()->count());
    }

    public function test_retention_purges_recent_views_past_180_days(): void
    {
        $platform = Platform::factory()->create();
        $account = $this->seedAccount($platform);
        $now = Carbon::now();

        CustomerRecentView::query()->insert([
            [
                'customer_account_id' => $account->id,
                'platform_id' => $platform->id,
                'object_type' => CustomerRecentView::TYPE_PROFILE,
                'object_ref' => 1,
                'view_count' => 1,
                'view_seq' => 1,
                'first_viewed_at' => (clone $now)->subDays(CustomerRecentView::RETENTION_DAYS + 1),
                'last_viewed_at' => (clone $now)->subDays(CustomerRecentView::RETENTION_DAYS + 1),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'customer_account_id' => $account->id,
                'platform_id' => $platform->id,
                'object_type' => CustomerRecentView::TYPE_PROFILE,
                'object_ref' => 2,
                'view_count' => 1,
                'view_seq' => 2,
                'first_viewed_at' => (clone $now)->subDays(CustomerRecentView::RETENTION_DAYS - 1),
                'last_viewed_at' => (clone $now)->subDays(CustomerRecentView::RETENTION_DAYS - 1),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->artisan('crm:purge-customer-data')->assertExitCode(0);

        $this->assertSame(0, CustomerRecentView::query()->where('object_ref', 1)->count());
        $this->assertSame(1, CustomerRecentView::query()->where('object_ref', 2)->count());
    }

    public function test_retention_purges_compare_sets_30_days_after_last_update(): void
    {
        $platform = Platform::factory()->create();
        $account = $this->seedAccount($platform);
        $now = Carbon::now();

        $stale = CustomerCompareSet::query()->create([
            'customer_account_id' => $account->id,
            'platform_id' => $platform->id,
            'last_activity_at' => (clone $now)->subDays(CustomerCompareSet::RETENTION_DAYS + 1),
        ]);
        CustomerCompareItem::query()->create([
            'compare_set_id' => $stale->id,
            'customer_account_id' => $account->id,
            'platform_id' => $platform->id,
            'object_type' => CustomerCompareItem::TYPE_PROFILE,
            'object_ref' => 4242,
            'position' => 1,
            'added_at' => (clone $now)->subDays(CustomerCompareSet::RETENTION_DAYS + 1),
        ]);

        $this->artisan('crm:purge-customer-data')->assertExitCode(0);

        $this->assertSame(0, CustomerCompareSet::query()->count());
        $this->assertSame(0, CustomerCompareItem::query()->count());
    }

    public function test_retention_keeps_a_compare_set_updated_inside_the_window(): void
    {
        $platform = Platform::factory()->create();
        $account = $this->seedAccount($platform);

        CustomerCompareSet::query()->create([
            'customer_account_id' => $account->id,
            'platform_id' => $platform->id,
            'last_activity_at' => Carbon::now()->subDays(CustomerCompareSet::RETENTION_DAYS - 1),
        ]);

        $this->artisan('crm:purge-customer-data')->assertExitCode(0);

        $this->assertSame(1, CustomerCompareSet::query()->count());
    }

    // ------------------------------------------------------------------ helpers

    private function seedAccount(Platform $platform): CustomerAccount
    {
        $body = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/sync', $body, $this->signHeaders($platform->id, $body))->assertOk();

        return CustomerAccount::query()->firstOrFail();
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
