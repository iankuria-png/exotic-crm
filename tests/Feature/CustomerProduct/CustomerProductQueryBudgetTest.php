<?php

namespace Tests\Feature\CustomerProduct;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Query budgets for the customer-product endpoints.
 *
 * These sit on page render for signed-in members, and `recent/record` fires
 * once per profile view, so an accidental N+1 here is a site-wide slowdown
 * rather than a slow page. The ceilings below are the measured counts plus a
 * small allowance; if a change pushes past one, that is the signal to look at
 * the query log rather than to raise the number.
 *
 * Counts include four queries this feature does not own: the shared-key
 * lookup, the platform resolve, and two Laravel Pulse telemetry writes.
 */
class CustomerProductQueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private const SHARED_KEY = 'customer-product-shared-key';

    /** Measured counts at the time of writing, plus two. */
    private const BUDGETS = [
        '/saved' => 10,
        '/saved/add' => 14,
        '/saved/remove' => 12,
        '/recent' => 11,
        '/recent/record' => 11,
        '/compare' => 10,
        '/compare/add' => 15,
        '/compare/remove' => 13,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.exotic_crm_sync.shared_key' => self::SHARED_KEY,
            'services.wp_service_auth.platform_allowlist' => [],
        ]);

        User::factory()->create(['role' => 'admin', 'status' => 'active']);
    }

    public function test_every_endpoint_stays_inside_its_query_budget(): void
    {
        $platform = Platform::factory()->create();

        // Link the account first so we measure steady state, not first touch.
        $seed = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/sync', $seed, $this->signHeaders($platform->id, $seed))->assertOk();

        $calls = [
            '/saved' => [],
            '/saved/add' => ['object_ref' => 111],
            '/saved/remove' => ['object_ref' => 111],
            '/recent' => [],
            '/recent/record' => ['object_ref' => 222],
            '/compare' => [],
            '/compare/add' => ['object_ref' => 333],
            '/compare/remove' => ['object_ref' => 333],
        ];

        $over = [];

        foreach ($calls as $path => $extra) {
            $body = $this->memberBody($extra);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->postJson('/api/wp-svc/customer'.$path, $body, $this->signHeaders($platform->id, $body));
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            if ($count > self::BUDGETS[$path]) {
                $over[] = sprintf('%s used %d queries, budget %d', $path, $count, self::BUDGETS[$path]);
            }
        }

        $this->assertSame([], $over, "Query budget exceeded:\n".implode("\n", $over));
    }

    /**
     * The hottest endpoint must not build the workspace summary. Reading saved
     * ids, the compare set, and a recent count on every profile view is three
     * queries per page for a body the caller discards.
     */
    public function test_recording_a_view_does_not_build_the_workspace_summary(): void
    {
        $platform = Platform::factory()->create();

        $seed = $this->memberBody();
        $this->postJson('/api/wp-svc/customer/sync', $seed, $this->signHeaders($platform->id, $seed))->assertOk();

        $body = $this->memberBody(['object_ref' => 4444]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->postJson('/api/wp-svc/customer/recent/record', $body, $this->signHeaders($platform->id, $body));
        $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
        DB::disableQueryLog();

        $response->assertStatus(201)->assertJsonMissingPath('saved_profile_ids');

        $this->assertStringNotContainsString('customer_saved_objects', $queries);
        $this->assertStringNotContainsString('customer_compare_sets', $queries);
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
