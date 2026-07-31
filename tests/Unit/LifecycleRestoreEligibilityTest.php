<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Deal;
use App\Models\LifecycleRestoreRun;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\ProfileLifecycleRestoreService;
use App\Support\ClientLifecycleState;
use App\Support\LifecycleRestoreEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LifecycleRestoreEligibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_history_selects_only_profiles_that_were_paid_for(): void
    {
        $platform = $this->createPlatform();

        $paid = $this->createOfflineClient($platform, 9001);
        $this->createPaidDeal($paid);

        $neverPaid = $this->createOfflineClient($platform, 9002);

        $ids = $this->idsFor($platform, ['history_mode' => LifecycleRestoreEligibility::HISTORY_PAID]);

        $this->assertContains($paid->id, $ids);
        $this->assertNotContains($neverPaid->id, $ids, 'a never-paid profile is not a recoverable asset');
    }

    public function test_any_wp_profile_includes_never_paid_profiles(): void
    {
        $platform = $this->createPlatform();
        $neverPaid = $this->createOfflineClient($platform, 9003);

        $ids = $this->idsFor($platform, ['history_mode' => LifecycleRestoreEligibility::HISTORY_ANY]);

        $this->assertContains($neverPaid->id, $ids);
    }

    public function test_previously_published_sits_between_paid_and_any(): void
    {
        $platform = $this->createPlatform();

        // Was live once (trial), but never paid.
        $trialled = $this->createOfflineClient($platform, 9004, ['first_activated_at' => now()->subYear()]);
        // Never activated, never paid, never churned.
        $untouched = $this->createOfflineClient($platform, 9005);

        $ids = $this->idsFor($platform, [
            'history_mode' => LifecycleRestoreEligibility::HISTORY_PREVIOUSLY_PUBLISHED,
        ]);

        $this->assertContains($trialled->id, $ids);
        $this->assertNotContains($untouched->id, $ids);
    }

    public function test_safety_toggles_exclude_high_risk_duplicates_and_bad_close_reasons(): void
    {
        $platform = $this->createPlatform();

        $clean = $this->createOfflineClient($platform, 9010);
        $this->createPaidDeal($clean);

        $highRisk = $this->createOfflineClient($platform, 9011, ['is_high_risk' => true]);
        $this->createPaidDeal($highRisk);

        $duplicate = $this->createOfflineClient($platform, 9012, ['duplicate_of' => $clean->id]);
        $this->createPaidDeal($duplicate);

        $ids = $this->idsFor($platform, []); // defaults: all safety toggles on

        $this->assertContains($clean->id, $ids);
        $this->assertNotContains($highRisk->id, $ids);
        $this->assertNotContains($duplicate->id, $ids);

        // …and disabling the toggles lets them back in.
        $relaxed = $this->idsFor($platform, [
            'exclude_high_risk' => false,
            'exclude_duplicates' => false,
        ]);

        $this->assertContains($highRisk->id, $relaxed);
        $this->assertContains($duplicate->id, $relaxed);
    }

    public function test_a_bad_close_reason_is_never_republished_by_default(): void
    {
        $platform = $this->createPlatform();

        $inappropriate = $this->createOfflineClient($platform, 9020, [
            'closed_at' => null,
            'close_reason_code' => 'inappropriate',
        ]);
        $this->createPaidDeal($inappropriate);

        $ids = $this->idsFor($platform, []);

        $this->assertNotContains($inappropriate->id, $ids);
    }

    public function test_already_restored_profiles_are_not_selected_again(): void
    {
        $platform = $this->createPlatform();
        $restored = $this->createOfflineClient($platform, 9030, ['lifecycle_restored_at' => now()]);
        $this->createPaidDeal($restored);

        $this->assertNotContains($restored->id, $this->idsFor($platform, []));
    }

    public function test_quality_filters_require_image_and_minimum_seo_score(): void
    {
        $platform = $this->createPlatform();

        $good = $this->createOfflineClient($platform, 9040, [
            'main_image_url' => 'https://example.test/a.jpg',
            'seo_score' => 70,
        ]);
        $this->createPaidDeal($good);

        $bare = $this->createOfflineClient($platform, 9041, [
            'main_image_url' => null,
            'display_image_url' => null,
            'seo_score' => 10,
        ]);
        $this->createPaidDeal($bare);

        $ids = $this->idsFor($platform, ['require_image' => true, 'min_seo_score' => 50]);

        $this->assertContains($good->id, $ids);
        $this->assertNotContains($bare->id, $ids);
    }

    public function test_dating_rule_resolves_expiry_in_priority_order(): void
    {
        $platform = $this->createPlatform();
        $service = app(ProfileLifecycleRestoreService::class);

        // 1. deal expiry wins over everything else
        $withDeal = $this->createOfflineClient($platform, 9050, ['churned_at' => now()->subDays(400)]);
        $this->createPaidDeal($withDeal, now()->subDays(30));
        Payment::factory()->create([
            'client_id' => $withDeal->id,
            'platform_id' => $platform->id,
            'end_date' => now()->subDays(200),
        ]);

        $this->assertSame(
            now()->subDays(30)->toDateString(),
            $service->resolveHistoricalExpiry($withDeal->fresh()->load(['deals', 'payments']))->toDateString()
        );
        $this->assertSame('deal', $service->resolveExpirySource($withDeal->fresh()->load(['deals', 'payments'])));

        // 2. payment end_date when there is no deal expiry
        $withPayment = $this->createOfflineClient($platform, 9051, ['churned_at' => now()->subDays(400)]);
        Payment::factory()->create([
            'client_id' => $withPayment->id,
            'platform_id' => $platform->id,
            'end_date' => now()->subDays(120),
        ]);

        $loaded = $withPayment->fresh()->load(['deals', 'payments']);
        $this->assertSame(now()->subDays(120)->toDateString(), $service->resolveHistoricalExpiry($loaded)->toDateString());
        $this->assertSame('payment', $service->resolveExpirySource($loaded));

        // 3. churned_at when there is neither
        $churnedOnly = $this->createOfflineClient($platform, 9052, ['churned_at' => now()->subDays(300)]);
        $loaded = $churnedOnly->fresh()->load(['deals', 'payments']);
        $this->assertSame(now()->subDays(300)->toDateString(), $service->resolveHistoricalExpiry($loaded)->toDateString());
        $this->assertSame('churned_at', $service->resolveExpirySource($loaded));

        // 4. updated_at as the last resort
        $bare = $this->createOfflineClient($platform, 9053);
        $loaded = $bare->fresh()->load(['deals', 'payments']);
        $this->assertSame('updated_at', $service->resolveExpirySource($loaded));
    }

    public function test_the_ninety_day_rule_picks_expired_or_archived(): void
    {
        $service = app(ProfileLifecycleRestoreService::class);
        $run = new LifecycleRestoreRun(['target_state' => null]);

        $this->assertSame(
            ClientLifecycleState::EXPIRED,
            $service->resolveLandingState($run, now()->subDays(10)),
            'recently lapsed profiles return to city listings'
        );

        $this->assertSame(
            ClientLifecycleState::ARCHIVED,
            $service->resolveLandingState($run, now()->subDays(400)),
            'long-dead profiles keep their URL but stay out of listings'
        );

        // An explicit target overrides the age rule.
        $forced = new LifecycleRestoreRun(['target_state' => ClientLifecycleState::EXPIRED]);
        $this->assertSame(
            ClientLifecycleState::EXPIRED,
            $service->resolveLandingState($forced, now()->subDays(400))
        );
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    /** @return array<int> */
    private function idsFor(Platform $platform, array $filters): array
    {
        return (new LifecycleRestoreEligibility($filters))
            ->query((int) $platform->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function createOfflineClient(Platform $platform, int $wpPostId, array $overrides = []): Client
    {
        return Client::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'name' => 'Offline ' . $wpPostId,
            'profile_status' => 'private',
            'lifecycle_state' => ClientLifecycleState::ACTIVE,
            'closed_at' => null,
            'duplicate_of' => null,
            'is_high_risk' => false,
        ], $overrides));
    }

    private function createPaidDeal(Client $client, $expiresAt = null): Deal
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
            'domain' => 'tm-' . Str::random(6) . '.example.test',
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
