<?php

namespace Tests\Feature\CustomerProduct;

use App\Models\CustomerPreferenceProfile;
use App\Models\CustomerPreferenceSignal;
use App\Models\CustomerRecentView;
use App\Models\CustomerSavedObject;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class WpCustomerRecommendationsTest extends TestCase
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

    public function test_a_member_signal_updates_the_stored_preference_profile(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody([
            'signal_type' => CustomerPreferenceSignal::SIGNAL_ONBOARDING_LIKE,
            'object_type' => CustomerPreferenceSignal::OBJECT_PROFILE,
            'object_ref' => 5150,
            'source_surface' => 'onboarding_modal',
            'fact_tokens' => [
                'location' => ['term:22', 'parent:4'],
                'services' => ['massage'],
                'build' => ['curvy'],
                'trust' => ['verified'],
            ],
        ]);

        $response = $this->postJson('/api/wp-svc/customer/preferences/signal', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('preference_profile.signal_count', 1)
            ->assertJsonPath('preference_profile.positive_signal_count', 1)
            ->assertJsonPath('preference_profile.personalised', false);

        $weights = $response->json('preference_profile.facet_weights');
        $this->assertGreaterThan(2.9, $weights['services']['massage']);
        $this->assertLessThanOrEqual(3.0, $weights['services']['massage']);

        $this->assertDatabaseHas('customer_preference_profiles', [
            'signal_count' => 1,
            'positive_signal_count' => 1,
        ]);
    }

    public function test_personalised_requires_eight_total_and_three_positive_signals(): void
    {
        $platform = Platform::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $body = $this->signalBody(CustomerPreferenceSignal::SIGNAL_ONBOARDING_SKIP, 1000 + $i, ['build' => ['slim']]);
            $this->postJson('/api/wp-svc/customer/preferences/signal', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        for ($i = 1; $i <= 3; $i++) {
            $body = $this->signalBody(CustomerPreferenceSignal::SIGNAL_MORE_LIKE_THIS, 2000 + $i, ['services' => ['massage']]);
            $this->postJson('/api/wp-svc/customer/preferences/signal', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $read = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/preferences', $read, $this->signHeaders($platform->id, $read))
            ->assertOk()
            ->assertJsonPath('preference_profile.signal_count', 8)
            ->assertJsonPath('preference_profile.positive_signal_count', 3)
            ->assertJsonPath('preference_profile.personalised', true);
    }

    public function test_invalid_preference_payloads_fail_hard_on_the_standalone_endpoint(): void
    {
        $platform = Platform::factory()->create();
        $bad = $this->memberBody([
            'signal_type' => 'preference.raw_text',
            'object_type' => CustomerPreferenceSignal::OBJECT_PROFILE,
            'object_ref' => 5150,
            'source_surface' => 'onboarding_modal',
            'fact_tokens' => ['services' => ['massage']],
        ]);

        $this->postJson('/api/wp-svc/customer/preferences/signal', $bad, $this->signHeaders($platform->id, $bad))
            ->assertStatus(422);

        $this->assertSame(0, CustomerPreferenceSignal::query()->count());
        $this->assertSame(0, CustomerPreferenceProfile::query()->count());
    }

    public function test_saved_search_preference_can_use_bounded_context_without_object_ref(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody([
            'signal_type' => CustomerPreferenceSignal::SIGNAL_BEHAVIOR_SAVED_SEARCH,
            'object_type' => CustomerPreferenceSignal::OBJECT_SAVED_SEARCH,
            'object_ref' => null,
            'source_surface' => 'discovery_rail',
            'context' => [
                'route_family' => 'services',
                'route_value' => 'massage',
                'filters' => ['verified', 'new_today'],
                'ignored_url' => 'https://example.com/not-stored',
            ],
            'fact_tokens' => [
                'services' => ['massage'],
                'trust' => ['verified'],
            ],
        ]);

        $this->postJson('/api/wp-svc/customer/preferences/signal', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(201);

        $signal = CustomerPreferenceSignal::query()->firstOrFail();
        $this->assertNull($signal->object_ref);
        $this->assertSame('services', $signal->context_json['route_family']);
        $this->assertArrayNotHasKey('ignored_url', $signal->context_json);
    }

    public function test_token_weights_are_clamped_to_minus_twenty_and_twenty(): void
    {
        $platform = Platform::factory()->create();

        for ($i = 1; $i <= 5; $i++) {
            $body = $this->signalBody(CustomerPreferenceSignal::SIGNAL_MORE_LIKE_THIS, 3000 + $i, ['build' => ['curvy']]);
            $this->postJson('/api/wp-svc/customer/preferences/signal', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        for ($i = 1; $i <= 5; $i++) {
            $body = $this->signalBody(CustomerPreferenceSignal::SIGNAL_NOT_MY_TYPE, 4000 + $i, ['build' => ['slim']]);
            $this->postJson('/api/wp-svc/customer/preferences/signal', $body, $this->signHeaders($platform->id, $body))
                ->assertStatus(201);
        }

        $profile = CustomerPreferenceProfile::query()->firstOrFail();
        $this->assertSame(20, (int) $profile->facet_weights_json['build']['curvy']);
        $this->assertSame(-20, (int) $profile->facet_weights_json['build']['slim']);
    }

    public function test_optional_recommendation_metadata_never_breaks_the_canonical_save(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->memberBody([
            'object_ref' => 5150,
            'recommendation_fact_tokens' => ['raw_text' => ['not allowed']],
        ]);

        $this->postJson('/api/wp-svc/customer/saved/add', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(201)
            ->assertJsonPath('saved_count', 1);

        $this->assertSame(1, CustomerSavedObject::query()->count());
        $this->assertSame(0, CustomerPreferenceSignal::query()->count());
    }

    public function test_reset_deletes_recommendation_signals_and_preserves_canonical_product_state(): void
    {
        $platform = Platform::factory()->create();

        $save = $this->memberBody(['object_ref' => 5150]);
        $this->postJson('/api/wp-svc/customer/saved/add', $save, $this->signHeaders($platform->id, $save))->assertStatus(201);

        $view = $this->memberBody(['object_ref' => 6160]);
        $this->postJson('/api/wp-svc/customer/recent/record', $view, $this->signHeaders($platform->id, $view))->assertStatus(201);

        $signal = $this->signalBody(CustomerPreferenceSignal::SIGNAL_MORE_LIKE_THIS, 5150, ['services' => ['massage']]);
        $this->postJson('/api/wp-svc/customer/preferences/signal', $signal, $this->signHeaders($platform->id, $signal))->assertStatus(201);

        $reset = $this->memberBody(['confirm' => 'reset']);
        $this->postJson('/api/wp-svc/customer/preferences/reset', $reset, $this->signHeaders($platform->id, $reset))
            ->assertOk()
            ->assertJsonPath('reset', true)
            ->assertJsonPath('preference_profile.signal_count', 0)
            ->assertJsonPath('preference_profile.positive_signal_count', 0);

        $this->assertSame(0, CustomerPreferenceSignal::query()->count());
        $this->assertSame(1, CustomerSavedObject::query()->count());
        $this->assertSame(1, CustomerRecentView::query()->count());
        $this->assertNotNull(CustomerPreferenceProfile::query()->firstOrFail()->reset_at);
    }

    public function test_purge_removes_expired_preference_signals(): void
    {
        $platform = Platform::factory()->create();
        $old = $this->signalBody(CustomerPreferenceSignal::SIGNAL_MORE_LIKE_THIS, 5150, [
            'services' => ['massage'],
        ], ['occurred_at' => Carbon::now()->subDays(CustomerPreferenceSignal::RETENTION_DAYS + 5)->toIso8601String()]);
        $fresh = $this->signalBody(CustomerPreferenceSignal::SIGNAL_MORE_LIKE_THIS, 6160, [
            'services' => ['massage'],
        ]);

        $this->postJson('/api/wp-svc/customer/preferences/signal', $old, $this->signHeaders($platform->id, $old))->assertStatus(201);
        $this->postJson('/api/wp-svc/customer/preferences/signal', $fresh, $this->signHeaders($platform->id, $fresh))->assertStatus(201);

        $this->artisan('crm:purge-customer-data')->assertExitCode(0);

        $this->assertSame(1, CustomerPreferenceSignal::query()->count());
    }

    public function test_preference_routes_reject_non_members_and_unsigned_requests(): void
    {
        $platform = Platform::factory()->create();
        $body = $this->signalBody(CustomerPreferenceSignal::SIGNAL_MORE_LIKE_THIS, 5150, ['services' => ['massage']], [
            'wp_role' => 'escort',
        ]);

        $this->postJson('/api/wp-svc/customer/preferences/signal', $body, $this->signHeaders($platform->id, $body))
            ->assertStatus(422);
        $this->postJson('/api/wp-svc/customer/preferences/signal', $body)
            ->assertStatus(401);

        $read = $this->memberBody(['wp_role' => 'escort']);
        $this->postJson('/api/wp-svc/customer/preferences', $read, $this->signHeaders($platform->id, $read))
            ->assertStatus(422);
    }

    private function signalBody(string $signalType, int $objectRef, array $tokens, array $overrides = []): array
    {
        return $this->memberBody(array_merge([
            'signal_type' => $signalType,
            'object_type' => CustomerPreferenceSignal::OBJECT_PROFILE,
            'object_ref' => $objectRef,
            'source_surface' => 'recommendation_card',
            'fact_tokens' => $tokens,
        ], $overrides));
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
