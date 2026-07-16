<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Services\ClientLifecycleService;
use App\Services\WpSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_wp_sync_posts_lifecycle_state(): void
    {
        $platform = $this->createPlatform();
        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake(["{$base}/clients/555/lifecycle" => Http::response(['ok' => true], 200)]);

        (new WpSyncService($platform))->setLifecycleState(555, 'expired');

        Http::assertSent(function ($request) use ($base) {
            return $request->url() === "{$base}/clients/555/lifecycle"
                && $request['state'] === 'expired';
        });
    }

    public function test_archive_moves_expired_profile_to_archived(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createExpiredClient($platform, 700);
        $this->fakeWpLifecycle($platform, 700, 'archived');

        $fresh = app(ClientLifecycleService::class)->archive($client, null);

        $this->assertSame('archived', $fresh->lifecycle_state);
        $this->assertSame('publish', $fresh->profile_status);
        $this->assertNotNull($fresh->lifecycle_archived_at);
        $this->assertDatabaseHas('timeline_events', [
            'entity_id' => $client->id,
            'event_type' => 'profile_archived',
        ]);
    }

    public function test_archive_rejects_a_profile_that_is_not_expired(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 701,
            'profile_status' => 'publish',
            'lifecycle_state' => 'active',
        ]);
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        app(ClientLifecycleService::class)->archive($client, null);
    }

    public function test_unarchive_returns_archived_profile_to_expired(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 702,
            'profile_status' => 'publish',
            'lifecycle_state' => 'archived',
            'lifecycle_archived_at' => now()->subDay(),
        ]);
        $this->fakeWpLifecycle($platform, 702, 'expired');

        $fresh = app(ClientLifecycleService::class)->unarchive($client, null);

        $this->assertSame('expired', $fresh->lifecycle_state);
        $this->assertNull($fresh->lifecycle_archived_at);
    }

    public function test_command_archives_long_term_expired_profiles_only(): void
    {
        $platform = $this->createPlatform();
        $old = $this->createExpiredClient($platform, 710, now()->subDays(91));
        $recent = $this->createExpiredClient($platform, 711, now()->subDays(10));
        $this->fakeWpLifecycle($platform, 710, 'archived');

        $this->artisan('crm:archive-expired', ['--days' => 90])->assertExitCode(0);

        $this->assertSame('archived', $old->fresh()->lifecycle_state);
        $this->assertSame('expired', $recent->fresh()->lifecycle_state);
    }

    public function test_command_dry_run_writes_nothing(): void
    {
        $platform = $this->createPlatform();
        $old = $this->createExpiredClient($platform, 720, now()->subDays(120));
        Http::fake();

        $this->artisan('crm:archive-expired', ['--days' => 90, '--dry-run' => true])->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame('expired', $old->fresh()->lifecycle_state);
    }

    public function test_admin_can_toggle_lifecycle_policy_from_market_settings(): void
    {
        $platform = $this->createPlatform();
        $platform->forceFill(['lifecycle_policy_enabled' => false])->save();

        $admin = \App\Models\User::query()->create([
            'name' => 'Admin Tester',
            'email' => 'admin-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}", [
            'lifecycle_policy_enabled' => true,
            'reason' => 'Enable SEO lifecycle pilot for this market',
        ])
            ->assertOk()
            ->assertJsonPath('platform.lifecycle_policy_enabled', true)
            ->assertJsonPath('platform.lifecycle_policy_effective', true);

        $this->assertTrue((bool) $platform->fresh()->lifecycle_policy_enabled);

        // And back off.
        $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}", [
            'lifecycle_policy_enabled' => false,
        ])
            ->assertOk()
            ->assertJsonPath('platform.lifecycle_policy_enabled', false)
            ->assertJsonPath('platform.lifecycle_policy_effective', false);

        $this->assertFalse((bool) $platform->fresh()->lifecycle_policy_enabled);
    }

    public function test_lifecycle_master_switch_is_managed_from_settings(): void
    {
        $platform = $this->createPlatform(); // per-market flag ON

        $admin = $this->createAdminUser();
        \Laravel\Sanctum\Sanctum::actingAs($admin);

        // Default: enabled (config fallback), market flag applies.
        $this->getJson('/api/crm/settings/lifecycle')
            ->assertOk()
            ->assertJsonPath('master_enabled', true);
        $this->assertTrue($platform->fresh()->lifecycleEnabled());

        // Kill switch OFF from the CRM — every market reverts to legacy.
        $this->patchJson('/api/crm/settings/lifecycle', [
            'master_enabled' => false,
            'reason' => 'Emergency stop for lifecycle pilot',
        ])
            ->assertOk()
            ->assertJsonPath('master_enabled', false);

        $this->assertFalse($platform->fresh()->lifecycleEnabled(), 'master off must override per-market flag');
        $this->artisan('crm:archive-expired')->expectsOutputToContain('master switch is off')->assertExitCode(0);

        // Back on.
        $this->patchJson('/api/crm/settings/lifecycle', ['master_enabled' => true])
            ->assertOk()
            ->assertJsonPath('master_enabled', true);
        $this->assertTrue($platform->fresh()->lifecycleEnabled());
    }

    public function test_sync_shared_key_header_follows_platform_toggle(): void
    {
        config()->set('services.exotic_crm_sync.shared_key', 'test-shared-key-123');
        config()->set('services.exotic_crm_sync.shared_key_platform_ids', ''); // env allowlist empty

        $platform = $this->createPlatform();
        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake(["{$base}/clients/900/lifecycle" => Http::response(['ok' => true], 200)]);

        // Toggle OFF (default): no shared key header.
        (new WpSyncService($platform))->setLifecycleState(900, 'expired');
        Http::assertSent(fn ($request) => !$request->hasHeader('X-Exotic-CRM-Sync-Key'));

        // Toggle ON via the market settings column: header present.
        $platform->forceFill(['sync_shared_key_enabled' => true])->save();
        (new WpSyncService($platform->fresh()))->setLifecycleState(900, 'expired');
        Http::assertSent(fn ($request) => $request->hasHeader('X-Exotic-CRM-Sync-Key', 'test-shared-key-123'));
    }

    private function createAdminUser(): \App\Models\User
    {
        return \App\Models\User::query()->create([
            'name' => 'Admin Tester',
            'email' => 'admin-' . uniqid() . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }

    public function test_subscriptions_check_skips_wp_deactivation_on_lifecycle_markets(): void
    {
        // Lifecycle market: the legacy subscriptions:check must NOT touch the
        // market's WordPress database (its direct-DB privatisation would race the
        // reconciler and take the profile offline). Payment still flips to expired.
        // The fake platform has no reachable WP database, so if the gate failed the
        // command would error on the connection attempt and exit non-zero.
        Http::fake();
        $platform = $this->createPlatform(); // lifecycle enabled

        $payment = \App\Models\Payment::factory()->create([
            'platform_id' => $platform->id,
            'status' => 'completed',
            'end_date' => now()->subDay(),
            'phone' => '254700000001',
        ]);

        $this->artisan('subscriptions:check')->assertExitCode(0);

        $this->assertSame('expired', $payment->fresh()->status);
    }

    public function test_market_lifecycle_toggle_pushes_policy_flag_to_wordpress(): void
    {
        $platform = $this->createPlatform();
        $platform->forceFill(['lifecycle_policy_enabled' => false])->save();
        $base = rtrim((string) $platform->wp_api_url, '/');

        Http::fake(["{$base}/lifecycle-policy" => Http::response(['ok' => true, 'enabled' => true], 200)]);

        \Laravel\Sanctum\Sanctum::actingAs($this->createAdminUser());

        $this->patchJson("/api/crm/settings/integrations/platforms/{$platform->id}", [
            'lifecycle_policy_enabled' => true,
        ])->assertOk();

        Http::assertSent(function ($request) use ($base) {
            return $request->url() === "{$base}/lifecycle-policy" && (string) $request['enabled'] === '1';
        });

        // Master switch off → every opted-in market gets pushed enabled=0.
        Http::fake(["{$base}/lifecycle-policy" => Http::response(['ok' => true, 'enabled' => false], 200)]);
        $this->patchJson('/api/crm/settings/lifecycle', ['master_enabled' => false])->assertOk();

        Http::assertSent(function ($request) use ($base) {
            return $request->url() === "{$base}/lifecycle-policy" && (string) $request['enabled'] === '0';
        });

        // Restore for other tests sharing the feature_settings row.
        $this->patchJson('/api/crm/settings/lifecycle', ['master_enabled' => true])->assertOk();
    }

    public function test_sync_one_remaps_stale_lifecycle_state_from_wordpress(): void
    {
        // Regression (Lala, ssudan pilot): CRM column stuck on 'expired' while
        // WordPress says 'active' — the single-profile sync (Sync from WP button)
        // must remap the lifecycle and shed the stale expiry stamp.
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 3178,
            'profile_status' => 'publish',
            'lifecycle_state' => 'expired',
            'lifecycle_expired_at' => now()->subDays(2),
        ]);

        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake([
            "{$base}/clients/3178" => Http::response([
                'wp_post_id' => 3178,
                'wp_user_id' => 701,
                'name' => 'Lala',
                'phone' => '+211980497725',
                'city' => 'Atla-Bara',
                'post_status' => 'publish',
                'crm_lifecycle_state' => 'active',
                'escort_expire' => now()->addDay()->timestamp,
                'needs_payment' => false,
                'notactive' => false,
                'main_image_url' => '',
                'modified_at' => now()->toIso8601String(),
            ], 200),
        ]);

        $fresh = (new \App\Services\ClientSyncService($platform))->syncOne(3178);

        $this->assertSame('active', $fresh->lifecycle_state);
        $this->assertNull($fresh->lifecycle_expired_at);
        $this->assertSame($client->id, $fresh->id);
    }

    public function test_archive_is_rejected_when_market_has_not_opted_in(): void
    {
        $platform = $this->createPlatform();
        $platform->forceFill(['lifecycle_policy_enabled' => false])->save();
        $client = $this->createExpiredClient($platform, 730);
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        app(ClientLifecycleService::class)->archive($client->fresh(), null);
    }

    private function createExpiredClient(Platform $platform, int $wpPostId, $expiredAt = null): Client
    {
        return Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'name' => 'Expired ' . $wpPostId,
            'profile_status' => 'publish',
            'lifecycle_state' => 'expired',
            'lifecycle_expired_at' => $expiredAt ?? now()->subDays(100),
            'escort_expire' => now()->subDays(100)->timestamp,
        ]);
    }

    private function fakeWpLifecycle(Platform $platform, int $wpPostId, string $state): void
    {
        $base = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            "{$base}/clients/{$wpPostId}/lifecycle" => Http::response(['ok' => true, 'crm_lifecycle_state' => $state], 200),
            "{$base}/clients/{$wpPostId}" => Http::response([
                'wp_post_id' => $wpPostId,
                'wp_user_id' => $wpPostId + 1000,
                'name' => 'Expired ' . $wpPostId,
                'phone' => '+255700000000',
                'city' => 'Kigamboni',
                'post_status' => 'publish',
                'crm_lifecycle_state' => $state,
                'escort_expire' => now()->subDays(100)->timestamp,
                'needs_payment' => false,
                'notactive' => false,
                'main_image_url' => '',
                'modified_at' => now()->toIso8601String(),
            ], 200),
        ]);
    }

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Tanzania Market',
            'domain' => 'tz-' . Str::random(6) . '.example.test',
            'country' => 'Tanzania',
            'timezone' => 'Africa/Dar_es_Salaam',
            'phone_prefix' => '255',
            'currency_code' => 'TZS',
            'is_active' => true,
            'lifecycle_policy_enabled' => true,
            'wp_api_url' => 'https://tz.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
