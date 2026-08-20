<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\LifecycleRestoreRun;
use App\Models\Platform;
use App\Services\ProfileLifecycleRestoreService;
use App\Support\ClientLifecycleState;
use App\Support\LifecycleRestoreEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LifecycleRestoreTest extends TestCase
{
    use RefreshDatabase;

    private const DIRTY_BIO = '<p>Call me on 0794305988 any time. I am 165cm.</p>';

    public function test_a_dry_run_writes_nothing(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createOfflineClient($platform, 7001);
        $this->createPaidDeal($client, now()->subDays(20));
        $this->fakeWp($platform, 7001);

        $run = $this->makeRun($platform, LifecycleRestoreRun::MODE_DRY);
        $result = app(ProfileLifecycleRestoreService::class)->execute($run);

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(0, $result['restored']);

        Http::assertNothingSent();

        $fresh = $client->fresh();
        $this->assertSame('private', $fresh->profile_status);
        $this->assertNull($fresh->lifecycle_restored_at);
        $this->assertSame(LifecycleRestoreRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_a_live_run_republishes_stamps_the_cohort_and_scrubs_the_bio(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createOfflineClient($platform, 7002);
        $this->createPaidDeal($client, now()->subDays(20)); // recent → Expired
        $this->fakeWp($platform, 7002);

        $run = $this->makeRun($platform, LifecycleRestoreRun::MODE_LIVE);
        $result = app(ProfileLifecycleRestoreService::class)->execute($run);

        $this->assertSame(1, $result['restored']);
        $this->assertSame(0, $result['failed']);

        // The profile was republished via the lifecycle endpoint…
        Http::assertSent(function ($request) use ($platform) {
            $base = rtrim((string) $platform->wp_api_url, '/');

            return $request->url() === "{$base}/clients/7002/lifecycle"
                && data_get($request->data(), 'state') === ClientLifecycleState::EXPIRED;
        });

        // …and its bio was scrubbed of contact details.
        Http::assertSent(function ($request) use ($platform) {
            $base = rtrim((string) $platform->wp_api_url, '/');
            if ($request->url() !== "{$base}/clients/7002/update") {
                return false;
            }

            return ! str_contains((string) data_get($request->data(), 'fields.content'), '0794305988');
        });

        $fresh = $client->fresh();
        $this->assertSame(ClientLifecycleState::EXPIRED, $fresh->lifecycle_state);
        $this->assertSame('publish', $fresh->profile_status);
        $this->assertNotNull($fresh->lifecycle_restored_at);
        $this->assertSame((int) $run->id, (int) $fresh->lifecycle_restore_run_id);
        $this->assertSame(
            now()->subDays(20)->toDateString(),
            $fresh->lifecycle_expired_at->toDateString(),
            'the real historical expiry is recovered, not stamped as today'
        );

        $this->assertDatabaseHas('timeline_events', [
            'entity_id' => $client->id,
            'event_type' => 'profile_restored_from_offline',
        ]);
    }

    public function test_a_long_dead_profile_lands_as_archived(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createOfflineClient($platform, 7003);
        $this->createPaidDeal($client, now()->subDays(400)); // > 90 days → Archived
        $this->fakeWp($platform, 7003);

        app(ProfileLifecycleRestoreService::class)->execute($this->makeRun($platform, LifecycleRestoreRun::MODE_LIVE));

        $this->assertSame(ClientLifecycleState::ARCHIVED, $client->fresh()->lifecycle_state);
    }

    public function test_revert_puts_the_cohort_back_offline(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createOfflineClient($platform, 7004);
        $this->createPaidDeal($client, now()->subDays(20));
        $this->fakeWpPrivateAfterDeactivate($platform, 7004);

        $run = $this->makeRun($platform, LifecycleRestoreRun::MODE_LIVE);
        app(ProfileLifecycleRestoreService::class)->execute($run);
        $this->assertSame('publish', $client->fresh()->profile_status);

        $result = app(ProfileLifecycleRestoreService::class)->revert($run->fresh());

        $this->assertSame(1, $result['reverted']);
        Http::assertSent(function ($request) use ($platform) {
            $base = rtrim((string) $platform->wp_api_url, '/');

            return $request->url() === "{$base}/clients/7004/deactivate";
        });

        $fresh = $client->fresh();
        $this->assertSame('private', $fresh->profile_status);
        $this->assertSame(ClientLifecycleState::ACTIVE, $fresh->lifecycle_state);
        $this->assertNull($fresh->lifecycle_restored_at);
        $this->assertNull($fresh->lifecycle_restore_run_id);
        $this->assertSame(LifecycleRestoreRun::STATUS_REVERTED, $run->fresh()->status);

        // The advertiser's real bio must survive a revert — it is the only copy.
        $this->assertSame(self::DIRTY_BIO, $fresh->bio_original_html);

        $event = \App\Models\TimelineEvent::query()
            ->where('entity_id', $client->id)
            ->where('event_type', 'profile_restore_reverted')
            ->first();
        $this->assertSame((int) $run->id, (int) data_get($event?->content, 'run_id'));
    }

    public function test_a_market_without_the_lifecycle_is_refused(): void
    {
        $platform = $this->createPlatform(lifecycleEnabled: false);
        $client = $this->createOfflineClient($platform, 7005);
        $this->createPaidDeal($client, now()->subDays(20));
        $this->fakeWp($platform, 7005);

        $run = $this->makeRun($platform, LifecycleRestoreRun::MODE_LIVE);
        $result = app(ProfileLifecycleRestoreService::class)->execute($run);

        $this->assertSame(0, $result['restored']);
        $this->assertSame(LifecycleRestoreRun::STATUS_FAILED, $run->fresh()->status);
        Http::assertNothingSent();
        $this->assertSame('private', $client->fresh()->profile_status);
    }

    public function test_any_wp_profile_includes_never_paid_while_paid_history_excludes_them(): void
    {
        $platform = $this->createPlatform();
        $neverPaid = $this->createOfflineClient($platform, 7006);
        $this->fakeWp($platform, 7006);

        $paidRun = $this->makeRun($platform, LifecycleRestoreRun::MODE_DRY, [
            'history_mode' => LifecycleRestoreEligibility::HISTORY_PAID,
        ]);
        $this->assertSame(0, app(ProfileLifecycleRestoreService::class)->execute($paidRun)['candidates']);

        $anyRun = $this->makeRun($platform, LifecycleRestoreRun::MODE_DRY, [
            'history_mode' => LifecycleRestoreEligibility::HISTORY_ANY,
        ]);
        $this->assertSame(1, app(ProfileLifecycleRestoreService::class)->execute($anyRun)['candidates']);
    }

    public function test_the_batch_limit_caps_a_run(): void
    {
        $platform = $this->createPlatform();

        foreach ([7010, 7011, 7012] as $postId) {
            $client = $this->createOfflineClient($platform, $postId);
            $this->createPaidDeal($client, now()->subDays(20));
        }
        $this->fakeWp($platform, 7010);

        $run = $this->makeRun($platform, LifecycleRestoreRun::MODE_LIVE);
        $run->forceFill(['batch_limit' => 2])->save();

        $result = app(ProfileLifecycleRestoreService::class)->execute($run);

        $this->assertSame(3, $result['candidates']);
        $this->assertSame(2, $result['restored'] + $result['failed'], 'the cap applies to work attempted');
    }

    public function test_one_bad_profile_does_not_kill_the_batch(): void
    {
        $platform = $this->createPlatform();
        $base = rtrim((string) $platform->wp_api_url, '/');

        $bad = $this->createOfflineClient($platform, 7020);
        $this->createPaidDeal($bad, now()->subDays(20));
        $good = $this->createOfflineClient($platform, 7021);
        $this->createPaidDeal($good, now()->subDays(20));

        Http::fake([
            "{$base}/clients/7020/lifecycle" => Http::response(['message' => 'boom'], 500),
            "{$base}/clients/*/lifecycle" => Http::response(['ok' => true], 200),
            "{$base}/clients/*/deactivate" => Http::response(['ok' => true], 200),
            "{$base}/clients/*/update" => Http::response(['ok' => true], 200),
            "{$base}/clients/*" => Http::response($this->wpClientPayload(7021), 200),
        ]);

        $result = app(ProfileLifecycleRestoreService::class)
            ->execute($this->makeRun($platform, LifecycleRestoreRun::MODE_LIVE));

        $this->assertSame(1, $result['failed']);
        $this->assertSame(1, $result['restored']);
        $this->assertNull($bad->fresh()->lifecycle_restored_at);
    }

    public function test_market_rollback_deactivates_only_restricted_profiles_for_that_market(): void
    {
        $platform = $this->createPlatform();
        $otherPlatform = $this->createPlatform();

        $expired = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 7030,
            'profile_status' => 'publish',
            'lifecycle_state' => ClientLifecycleState::EXPIRED,
            'lifecycle_expired_at' => now()->subDays(12),
            'lifecycle_restored_at' => now()->subDay(),
            'lifecycle_restore_run_id' => 88,
        ]);
        $active = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 7031,
            'profile_status' => 'publish',
            'lifecycle_state' => ClientLifecycleState::ACTIVE,
            'lifecycle_restored_at' => now()->subDay(),
        ]);
        $otherMarket = Client::factory()->create([
            'platform_id' => $otherPlatform->id,
            'wp_post_id' => 8030,
            'profile_status' => 'publish',
            'lifecycle_state' => ClientLifecycleState::EXPIRED,
            'lifecycle_restored_at' => now()->subDay(),
        ]);

        $this->fakeWpPrivateAfterDeactivate($platform, 7030);

        $result = app(ProfileLifecycleRestoreService::class)
            ->rollbackMarket((int) $platform->id, null, 'market_lifecycle_disabled');

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(1, $result['reverted']);
        $this->assertSame(0, $result['failed']);

        $freshExpired = $expired->fresh();
        $this->assertSame('private', $freshExpired->profile_status);
        $this->assertSame(ClientLifecycleState::ACTIVE, $freshExpired->lifecycle_state);
        $this->assertNull($freshExpired->lifecycle_restored_at);
        $this->assertNull($freshExpired->lifecycle_restore_run_id);

        $this->assertSame('publish', $active->fresh()->profile_status);
        $this->assertSame(ClientLifecycleState::ACTIVE, $active->fresh()->lifecycle_state);
        $this->assertSame('publish', $otherMarket->fresh()->profile_status);
        $this->assertSame(ClientLifecycleState::EXPIRED, $otherMarket->fresh()->lifecycle_state);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/clients/7030/deactivate'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/clients/7031/'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/clients/8030/'));
    }

    public function test_market_rollback_does_not_clear_crm_state_when_wordpress_stays_public(): void
    {
        $platform = $this->createPlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 7040,
            'profile_status' => 'publish',
            'lifecycle_state' => ClientLifecycleState::EXPIRED,
            'lifecycle_expired_at' => now()->subDays(12),
            'lifecycle_restored_at' => now()->subDay(),
            'lifecycle_restore_run_id' => 89,
        ]);
        $base = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            "{$base}/clients/7040/deactivate" => Http::response(['ok' => true], 200),
            "{$base}/clients/7040" => Http::response($this->wpClientPayload(7040, 'publish', ClientLifecycleState::EXPIRED), 200),
        ]);

        $result = app(ProfileLifecycleRestoreService::class)
            ->rollbackMarket((int) $platform->id, null, 'market_lifecycle_disabled');

        $this->assertSame(1, $result['candidates']);
        $this->assertSame(0, $result['reverted']);
        $this->assertSame(1, $result['failed']);

        $fresh = $client->fresh();
        $this->assertSame('publish', $fresh->profile_status);
        $this->assertSame(ClientLifecycleState::EXPIRED, $fresh->lifecycle_state);
        $this->assertNotNull($fresh->lifecycle_restored_at);
        $this->assertSame(89, (int) $fresh->lifecycle_restore_run_id);
    }

    public function test_restore_revert_skips_and_repairs_profiles_that_have_renewed(): void
    {
        $platform = $this->createPlatform();
        $run = LifecycleRestoreRun::create([
            'platform_id' => $platform->id,
            'requested_by' => null,
            'mode' => LifecycleRestoreRun::MODE_LIVE,
            'status' => LifecycleRestoreRun::STATUS_COMPLETED,
            'batch_limit' => 200,
            'filters' => null,
        ]);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 7041,
            'profile_status' => 'publish',
            'lifecycle_state' => ClientLifecycleState::EXPIRED,
            'lifecycle_expired_at' => now()->subDays(12),
            'lifecycle_restored_at' => now()->subDay(),
            'lifecycle_restore_run_id' => $run->id,
            'escort_expire' => now()->subDay()->timestamp,
        ]);
        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'active',
            'plan_type' => 'vip',
            'expires_at' => now()->addDays(7),
        ]);
        Http::fake();

        $result = app(ProfileLifecycleRestoreService::class)->revert($run);

        $this->assertSame(0, $result['reverted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['failed']);

        $fresh = $client->fresh();
        $this->assertSame('publish', $fresh->profile_status);
        $this->assertSame(ClientLifecycleState::ACTIVE, $fresh->lifecycle_state);
        $this->assertNull($fresh->lifecycle_expired_at);
        $this->assertNull($fresh->lifecycle_restored_at);
        $this->assertGreaterThan(now()->timestamp, (int) $fresh->escort_expire);

        Http::assertNothingSent();
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function makeRun(Platform $platform, string $mode, ?array $filters = null): LifecycleRestoreRun
    {
        return LifecycleRestoreRun::create([
            'platform_id' => $platform->id,
            'requested_by' => null,
            'mode' => $mode,
            'status' => LifecycleRestoreRun::STATUS_QUEUED,
            'target_state' => null,
            'batch_limit' => 200,
            'filters' => $filters,
        ]);
    }

    private function wpClientPayload(int $wpPostId, string $status = 'publish', string $lifecycleState = ClientLifecycleState::EXPIRED): array
    {
        return [
            'wp_post_id' => $wpPostId,
            'post_status' => $status,
            'crm_lifecycle_state' => $lifecycleState,
            'post' => ['id' => $wpPostId, 'content' => self::DIRTY_BIO],
            'modified_at' => now()->toIso8601String(),
        ];
    }

    private function fakeWp(Platform $platform, int $wpPostId): void
    {
        $base = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            "{$base}/clients/*/lifecycle" => Http::response(['ok' => true], 200),
            "{$base}/clients/*/deactivate" => Http::response(['ok' => true], 200),
            "{$base}/clients/*/update" => Http::response(['ok' => true], 200),
            "{$base}/clients/*" => Http::response($this->wpClientPayload($wpPostId), 200),
        ]);
    }

    private function fakeWpPrivateAfterDeactivate(Platform $platform, int $wpPostId): void
    {
        $base = rtrim((string) $platform->wp_api_url, '/');
        $deactivated = false;

        Http::fake(function ($request) use ($base, $wpPostId, &$deactivated) {
            if ($request->url() === "{$base}/clients/{$wpPostId}/deactivate") {
                $deactivated = true;

                return Http::response(['ok' => true], 200);
            }

            if ($request->url() === "{$base}/clients/{$wpPostId}/lifecycle") {
                return Http::response(['ok' => true], 200);
            }

            if ($request->url() === "{$base}/clients/{$wpPostId}/update") {
                return Http::response(['ok' => true], 200);
            }

            if ($request->url() === "{$base}/clients/{$wpPostId}") {
                return Http::response(
                    $this->wpClientPayload(
                        $wpPostId,
                        $deactivated ? 'private' : 'publish',
                        $deactivated ? ClientLifecycleState::ACTIVE : ClientLifecycleState::EXPIRED
                    ),
                    200
                );
            }

            return Http::response(['message' => 'Unexpected request'], 500);
        });
    }

    private function createOfflineClient(Platform $platform, int $wpPostId, array $overrides = []): Client
    {
        return Client::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'name' => 'Offline '.$wpPostId,
            'profile_status' => 'private',
            'lifecycle_state' => ClientLifecycleState::ACTIVE,
            'closed_at' => null,
            'duplicate_of' => null,
            'is_high_risk' => false,
        ], $overrides));
    }

    private function createPaidDeal(Client $client, $expiresAt): Deal
    {
        return Deal::factory()->create([
            'client_id' => $client->id,
            'platform_id' => $client->platform_id,
            'status' => 'expired',
            'expires_at' => $expiresAt,
        ]);
    }

    private function createPlatform(bool $lifecycleEnabled = true): Platform
    {
        return Platform::query()->create([
            'name' => 'Test Market',
            'domain' => 'tm-'.Str::random(6).'.example.test',
            'country' => 'South Sudan',
            'timezone' => 'Africa/Juba',
            'phone_prefix' => '211',
            'currency_code' => 'SSP',
            'is_active' => true,
            'lifecycle_policy_enabled' => $lifecycleEnabled,
            'wp_api_url' => 'https://tm.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }
}
