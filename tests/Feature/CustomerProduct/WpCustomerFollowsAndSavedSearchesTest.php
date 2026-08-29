<?php

namespace Tests\Feature\CustomerProduct;

use App\Models\CustomerAccount;
use App\Models\CustomerActivityEvent;
use App\Models\CustomerFollow;
use App\Models\CustomerSavedSearch;
use App\Models\Platform;
use App\Models\User;
use App\Services\CustomerProductService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4: follows and route-owned saved searches for signed-in members.
 */
class WpCustomerFollowsAndSavedSearchesTest extends TestCase
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

    // ---------------------------------------------------------------- follows

    public function test_a_member_can_follow_and_unfollow_a_profile(): void
    {
        $platform = Platform::factory()->create();

        $add = $this->memberBody([
            'follow_type' => CustomerFollow::TYPE_PROFILE,
            'object_ref' => 5150,
        ]);
        $this->postJson('/api/wp-svc/customer/follows/add', $add, $this->signHeaders($platform->id, $add))
            ->assertStatus(201)
            ->assertJsonPath('created', true)
            ->assertJsonPath('following', true)
            ->assertJsonPath('follow_profile_ids', [5150])
            ->assertJsonPath('follow_count', 1);

        $this->postJson('/api/wp-svc/customer/follows/add', $add, $this->signHeaders($platform->id, $add))
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('follow_count', 1);

        $remove = $add;
        $this->postJson('/api/wp-svc/customer/follows/remove', $remove, $this->signHeaders($platform->id, $remove))
            ->assertOk()
            ->assertJsonPath('removed', true)
            ->assertJsonPath('following', false)
            ->assertJsonPath('follow_count', 0);

        $this->assertSame(0, CustomerFollow::query()->count());
    }

    public function test_a_member_can_follow_a_location(): void
    {
        $platform = Platform::factory()->create();

        $add = $this->memberBody([
            'follow_type' => CustomerFollow::TYPE_LOCATION,
            'object_ref' => 44,
        ]);

        $this->postJson('/api/wp-svc/customer/follows/add', $add, $this->signHeaders($platform->id, $add))
            ->assertStatus(201)
            ->assertJsonPath('follow_location_ids', [44])
            ->assertJsonPath('follow_count', 1);

        $this->assertDatabaseHas('customer_activity_events', [
            'event_type' => CustomerActivityEvent::EVENT_FOLLOW_ADDED,
            'object_type' => CustomerFollow::TYPE_LOCATION,
            'object_ref' => 44,
        ]);
    }

    public function test_one_member_cannot_see_another_members_follows(): void
    {
        $platform = Platform::factory()->create();

        $mine = $this->memberBody([
            'follow_type' => CustomerFollow::TYPE_LOCATION,
            'object_ref' => 808,
        ]);
        $this->postJson('/api/wp-svc/customer/follows/add', $mine, $this->signHeaders($platform->id, $mine))
            ->assertStatus(201);

        $theirs = $this->memberBody(['wp_user_id' => 9001, 'email' => 'other@example.com']);
        $this->postJson('/api/wp-svc/customer/follows', $theirs, $this->signHeaders($platform->id, $theirs))
            ->assertOk()
            ->assertJsonPath('follow_count', 0)
            ->assertJsonPath('follow_profile_ids', [])
            ->assertJsonPath('follow_location_ids', []);
    }

    public function test_follow_rejects_unknown_types_and_bad_refs(): void
    {
        $platform = Platform::factory()->create();

        $badType = $this->memberBody(['follow_type' => 'visitor', 'object_ref' => 1]);
        $this->postJson('/api/wp-svc/customer/follows/add', $badType, $this->signHeaders($platform->id, $badType))
            ->assertStatus(422);

        $badRef = $this->memberBody(['follow_type' => CustomerFollow::TYPE_PROFILE, 'object_ref' => 0]);
        $this->postJson('/api/wp-svc/customer/follows/add', $badRef, $this->signHeaders($platform->id, $badRef))
            ->assertStatus(422);

        $this->assertSame(0, CustomerFollow::query()->count());
    }

    // ---------------------------------------------------------- saved searches

    public function test_a_member_can_save_discovery_searches_by_route_and_refinements(): void
    {
        $platform = Platform::factory()->create();

        $body = $this->memberBody([
            'route_family' => 'build',
            'route_value' => 'curvy',
            'label' => 'Curvy in Kilimani',
            'refinements' => [
                'city' => 22,
                'fresh' => 'new_today',
                'verified' => 1,
                'filters' => ['service_5', 'service_1'],
            ],
        ]);

        $this->postJson('/api/wp-svc/customer/saved-searches/add', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(201)
            ->assertJsonPath('created', true)
            ->assertJsonPath('saved_search_count', 1)
            ->assertJsonPath('saved_searches.0.route_family', 'build')
            ->assertJsonPath('saved_searches.0.route_value', 'curvy')
            ->assertJsonPath('saved_searches.0.refinements.city', 22)
            ->assertJsonPath('saved_searches.0.refinements.fresh', 'new_today');

        $this->postJson('/api/wp-svc/customer/saved-searches/add', $body, $this->signHeaders($platform->id, $body))
            ->assertOk()
            ->assertJsonPath('created', false)
            ->assertJsonPath('saved_search_count', 1);

        $this->assertSame(1, CustomerSavedSearch::query()->count());
    }

    public function test_saved_searches_distinguish_the_same_route_with_different_refinements(): void
    {
        $platform = Platform::factory()->create();

        foreach ([['fresh' => 'new_today'], ['fresh' => 'new_week']] as $refinements) {
            $body = $this->memberBody([
                'route_family' => 'services',
                'route_value' => 'massage',
                'refinements' => $refinements,
            ]);
            $this->postJson('/api/wp-svc/customer/saved-searches/add', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $this->assertSame(2, CustomerSavedSearch::query()->count());
    }

    public function test_saved_search_can_be_removed_by_owner_only(): void
    {
        $platform = Platform::factory()->create();

        $body = $this->memberBody(['route_family' => 'build', 'route_value' => 'curvy']);
        $response = $this->postJson('/api/wp-svc/customer/saved-searches/add', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(201);

        $searchId = (int) $response->json('saved_searches.0.id');

        $theirs = $this->memberBody([
            'wp_user_id' => 9001,
            'email' => 'other@example.com',
            'saved_search_id' => $searchId,
        ]);
        $this->postJson('/api/wp-svc/customer/saved-searches/remove', $theirs, $this->signHeaders($platform->id, $theirs))
            ->assertOk()
            ->assertJsonPath('removed', false);

        $remove = $this->memberBody(['saved_search_id' => $searchId]);
        $this->postJson('/api/wp-svc/customer/saved-searches/remove', $remove, $this->signHeaders($platform->id, $remove))
            ->assertOk()
            ->assertJsonPath('removed', true)
            ->assertJsonPath('saved_search_count', 0);
    }

    public function test_saved_searches_are_capped(): void
    {
        $platform = Platform::factory()->create();
        $account = $this->seedAccount($platform);

        $now = now();
        $rows = [];
        for ($i = 1; $i <= CustomerProductService::MAX_SAVED_SEARCHES; $i++) {
            $rows[] = [
                'customer_account_id' => $account->id,
                'platform_id' => $platform->id,
                'route_family' => 'build',
                'route_value' => 'curvy',
                'refinement_hash' => hash('sha256', (string) $i),
                'refinements_json' => json_encode(['city' => $i]),
                'label' => null,
                'saved_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        CustomerSavedSearch::query()->insert($rows);

        $overflow = $this->memberBody(['route_family' => 'build', 'route_value' => 'slim']);
        $this->postJson('/api/wp-svc/customer/saved-searches/add', $overflow, $this->signHeaders($platform->id, $overflow))
            ->assertStatus(422);

        $this->assertSame(CustomerProductService::MAX_SAVED_SEARCHES, CustomerSavedSearch::query()->count());
    }

    // -------------------------------------------------------------- permissions

    public function test_phase_four_routes_reject_non_members_and_unsigned_requests(): void
    {
        $platform = Platform::factory()->create();

        foreach (['follows/add', 'follows/remove', 'saved-searches/add', 'saved-searches/remove'] as $route) {
            $body = $this->memberBody([
                'wp_role' => 'escort',
                'follow_type' => CustomerFollow::TYPE_PROFILE,
                'object_ref' => 123,
                'route_family' => 'build',
                'route_value' => 'curvy',
                'saved_search_id' => 1,
            ]);
            $this->postJson('/api/wp-svc/customer/'.$route, $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(422);

            $this->postJson('/api/wp-svc/customer/'.$route, $body)
                ->assertStatus(401);
        }
    }

    public function test_two_platforms_do_not_share_follows_or_saved_searches(): void
    {
        $kenya = Platform::factory()->create();
        $uganda = Platform::factory()->create();

        $follow = $this->memberBody([
            'follow_type' => CustomerFollow::TYPE_PROFILE,
            'object_ref' => 6001,
        ]);
        $this->postJson('/api/wp-svc/customer/follows/add', $follow, $this->signHeaders($kenya->id, $follow))
            ->assertStatus(201);

        $search = $this->memberBody(['route_family' => 'build', 'route_value' => 'curvy']);
        $this->postJson('/api/wp-svc/customer/saved-searches/add', $search, $this->signHeaders($kenya->id, $search))
            ->assertStatus(201);

        $this->postJson('/api/wp-svc/customer/follows', $this->memberBody(), $this->signHeaders($uganda->id, $this->memberBody()))
            ->assertOk()
            ->assertJsonPath('follow_count', 0);

        $this->postJson('/api/wp-svc/customer/saved-searches', $this->memberBody(), $this->signHeaders($uganda->id, $this->memberBody()))
            ->assertOk()
            ->assertJsonPath('saved_search_count', 0)
            ->assertJsonPath('saved_searches', []);
    }

    // ---------------------------------------------------------- delete cascade

    public function test_forget_removes_follows_and_saved_searches(): void
    {
        $platform = Platform::factory()->create();

        $follow = $this->memberBody([
            'follow_type' => CustomerFollow::TYPE_PROFILE,
            'object_ref' => 7001,
        ]);
        $this->postJson('/api/wp-svc/customer/follows/add', $follow, $this->signHeaders($platform->id, $follow))
            ->assertStatus(201);

        $search = $this->memberBody(['route_family' => 'build', 'route_value' => 'curvy']);
        $this->postJson('/api/wp-svc/customer/saved-searches/add', $search, $this->signHeaders($platform->id, $search))
            ->assertStatus(201);

        $forget = ['wp_user_id' => 4242];
        $this->postJson('/api/wp-svc/customer/forget', $forget, $this->signHeaders($platform->id, $forget))
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $this->assertSame(0, CustomerAccount::query()->count());
        $this->assertSame(0, CustomerFollow::query()->count());
        $this->assertSame(0, CustomerSavedSearch::query()->count());
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
