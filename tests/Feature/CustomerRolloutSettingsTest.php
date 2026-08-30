<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * My Exotic rollout control.
 *
 * The WordPress side is faked here: its own behaviour (pinning, allowlisting,
 * effective-state resolution) is covered against a real WordPress install in
 * the plugin's integration script. What matters on this side is role gating,
 * validation, per-market isolation, that one unreachable market cannot blank
 * the roll-up, and that every write lands in the audit log.
 */
class CustomerRolloutSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function platform(array $overrides = []): Platform
    {
        return Platform::factory()->create(array_merge([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'is_active' => true,
            'wp_api_url' => 'https://exotickenya.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'sync',
            'wp_api_password' => 'secret',
        ], $overrides));
    }

    private function rolloutPayload(array $overrides = []): array
    {
        return array_merge([
            'market_key' => 'kenya',
            'master_enabled' => true,
            'market_enabled' => true,
            'enabled_markets' => ['kenya'],
            'features' => [
                ['key' => 'compare', 'configured' => true, 'rollback' => false, 'effective' => true, 'code_default' => true, 'source' => 'default', 'reason' => 'live'],
                ['key' => 'notifications', 'configured' => false, 'rollback' => false, 'effective' => false, 'code_default' => false, 'source' => 'default', 'reason' => 'flag_off'],
            ],
            'pinned_flags' => [],
            'pinned_rollbacks' => [],
            'pages' => ['ready' => true, 'pages' => [], 'missing' => []],
            'audit' => [],
            'site_url' => 'https://exotickenya.test/',
        ], $overrides);
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_rollout_requires_authentication(): void
    {
        $this->getJson('/api/crm/settings/customer-rollout')->assertUnauthorized();
    }

    public function test_agent_cannot_read_rollout(): void
    {
        $this->actingAsRole('agent');

        $this->getJson('/api/crm/settings/customer-rollout')->assertForbidden();
    }

    public function test_sub_admin_can_read_rollout(): void
    {
        $this->platform();
        $this->actingAsRole('sub_admin');
        Http::fake(['*' => Http::response($this->rolloutPayload(), 200)]);

        $this->getJson('/api/crm/settings/customer-rollout')
            ->assertOk()
            ->assertJsonPath('markets.0.reachable', true)
            ->assertJsonPath('markets.0.rollout.market_key', 'kenya')
            ->assertJsonPath('markets.0.rollout.features.0.key', 'compare');
    }

    public function test_unreachable_market_does_not_break_the_rollup(): void
    {
        $this->platform(['name' => 'Kenya']);
        $this->platform([
            'name' => 'Ghana',
            'country' => 'Ghana',
            'wp_api_url' => 'https://exoticghana.test/wp-json/exotic-crm-sync/v1',
        ]);
        $this->actingAsRole('admin');

        Http::fake([
            'exotickenya.test/*' => Http::response($this->rolloutPayload(), 200),
            'exoticghana.test/*' => Http::response(['message' => 'boom'], 500),
        ]);

        $response = $this->getJson('/api/crm/settings/customer-rollout')->assertOk();

        $markets = collect($response->json('markets'))->keyBy('name');
        $this->assertTrue($markets['Kenya']['reachable']);
        $this->assertFalse($markets['Ghana']['reachable']);
        $this->assertNotNull($markets['Ghana']['error']);
        $this->assertNull($markets['Ghana']['rollout']);
    }

    public function test_inactive_market_is_excluded(): void
    {
        $this->platform(['is_active' => false]);
        $this->actingAsRole('admin');
        Http::fake(['*' => Http::response($this->rolloutPayload(), 200)]);

        $this->getJson('/api/crm/settings/customer-rollout')
            ->assertOk()
            ->assertJsonCount(0, 'markets');
    }

    public function test_sub_admin_cannot_write_rollout(): void
    {
        $platform = $this->platform();
        $this->actingAsRole('sub_admin');

        $this->patchJson('/api/crm/settings/customer-rollout', [
            'platform_id' => $platform->id,
            'flags' => ['compare' => false],
        ])->assertForbidden();
    }

    public function test_admin_can_flip_a_flag_and_it_is_audited(): void
    {
        $platform = $this->platform();
        $user = $this->actingAsRole('admin');

        Http::fake([
            '*' => Http::response($this->rolloutPayload([
                'changed' => [['key' => 'flag:compare', 'to' => false]],
            ]), 200),
        ]);

        $this->patchJson('/api/crm/settings/customer-rollout', [
            'platform_id' => $platform->id,
            'flags' => ['compare' => false],
            'note' => 'pausing compare in Kenya',
        ])->assertOk();

        // The PATCH body must reach WordPress intact, and must not smuggle
        // anything beyond the fields the controller validated.
        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }
            $body = json_decode($request->body(), true);
            return isset($body['flags']['compare'])
                && $body['flags']['compare'] === false
                && ($body['note'] ?? null) === 'pausing compare in Kenya'
                && !array_key_exists('platform_id', $body);
        });

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'customer_rollout_update',
        ]);
    }

    public function test_reset_flags_is_forwarded_as_a_reset_not_a_false(): void
    {
        $platform = $this->platform();
        $this->actingAsRole('admin');
        Http::fake(['*' => Http::response($this->rolloutPayload(), 200)]);

        $this->patchJson('/api/crm/settings/customer-rollout', [
            'platform_id' => $platform->id,
            'reset_flags' => ['compare'],
        ])->assertOk();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST') {
                return false;
            }
            $body = json_decode($request->body(), true);
            // Un-pinning must never be expressed as `flags: {compare: false}`;
            // that would pin the market to false instead of releasing it.
            return ($body['reset_flags'] ?? null) === ['compare']
                && !array_key_exists('flags', $body);
        });
    }

    public function test_empty_change_set_is_rejected(): void
    {
        $platform = $this->platform();
        $this->actingAsRole('admin');
        Http::fake(['*' => Http::response($this->rolloutPayload(), 200)]);

        $this->patchJson('/api/crm/settings/customer-rollout', [
            'platform_id' => $platform->id,
            'note' => 'just a note',
        ])->assertStatus(422);
    }

    public function test_non_boolean_flag_is_rejected_before_reaching_wordpress(): void
    {
        $platform = $this->platform();
        $this->actingAsRole('admin');
        Http::fake();

        $this->patchJson('/api/crm/settings/customer-rollout', [
            'platform_id' => $platform->id,
            'flags' => ['compare' => 'maybe'],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_unknown_platform_is_rejected(): void
    {
        $this->actingAsRole('admin');
        Http::fake();

        $this->patchJson('/api/crm/settings/customer-rollout', [
            'platform_id' => 999999,
            'flags' => ['compare' => false],
        ])->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_admin_can_provision_pages_and_it_is_audited(): void
    {
        $platform = $this->platform();
        $user = $this->actingAsRole('admin');

        Http::fake(['*' => Http::response([
            'results' => [],
            'created' => ['home', 'compare'],
            'pages' => ['ready' => true, 'pages' => [], 'missing' => []],
        ], 200)]);

        $this->postJson('/api/crm/settings/customer-rollout/provision', [
            'platform_id' => $platform->id,
        ])
            ->assertOk()
            ->assertJsonPath('created.1', 'compare');

        $this->assertDatabaseHas('activity_logs', [
            'user_id' => $user->id,
            'action' => 'customer_rollout_provision',
        ]);
    }

    public function test_sub_admin_cannot_provision_pages(): void
    {
        $platform = $this->platform();
        $this->actingAsRole('sub_admin');

        $this->postJson('/api/crm/settings/customer-rollout/provision', [
            'platform_id' => $platform->id,
        ])->assertForbidden();
    }
}
