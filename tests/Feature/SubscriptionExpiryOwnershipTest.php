<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Services\ActiveSubscriptionProfileRepairService;
use App\Services\SubscriptionProvisioningService;
use App\Support\SubscriptionExpiry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Guards the single invariant that all six early-expiry defects violated:
 *
 *   WordPress owns when a subscription ends. The CRM records the value WordPress
 *   reports and never expires a profile from any other source.
 *
 * Production data for 1-2 Sep 2026 showed the nightly subscriptions:check sweep
 * privatising 70 and 86 fully-paid profiles on consecutive nights, each owed up to
 * 31 days, because it read a stale payments row instead of escort_expire.
 */
class SubscriptionExpiryOwnershipTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- sweep

    public function test_expiry_sweep_is_no_longer_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(fn ($event) => (string) $event->command);

        $this->assertTrue(
            $commands->filter(fn ($command) => str_contains($command, 'subscriptions:check'))->isEmpty(),
            'subscriptions:check must never be scheduled again: it expires profiles from a stale payments row.'
        );

        $this->assertTrue(
            $commands->filter(fn ($command) => str_contains($command, 'crm:reconcile-expired-subscriptions'))->isNotEmpty(),
            'crm:reconcile-expired-subscriptions is the sole expiry owner and must stay scheduled.'
        );
    }

    public function test_sweep_never_touches_wordpress_or_the_profile_even_when_a_payment_window_has_closed(): void
    {
        Http::fake();

        $platform = $this->createPlatform(['lifecycle_policy_enabled' => false]);
        $client = $this->createClient($platform, 7101);
        $client->forceFill([
            'profile_status' => 'publish',
            'needs_payment' => false,
            'notactive' => false,
            // Fully paid for another three weeks — exactly the renewed profile the
            // old sweep destroyed on its pre-renewal payment date.
            'escort_expire' => now()->addDays(21)->timestamp,
        ])->save();

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'completed',
            'end_date' => now()->subDay(),
            'phone' => '254700000001',
        ]);

        $this->artisan('subscriptions:check')->assertExitCode(0);

        // Bookkeeping only.
        $this->assertSame('expired', $payment->fresh()->status);

        // The profile is untouched and no WordPress call — nor SMS — was made.
        $fresh = $client->fresh();
        $this->assertSame('publish', $fresh->profile_status);
        $this->assertFalse((bool) $fresh->needs_payment);
        $this->assertFalse((bool) $fresh->notactive);
        $this->assertSame($client->escort_expire, $fresh->escort_expire);
        Http::assertNothingSent();
    }

    public function test_sweep_dry_run_writes_nothing(): void
    {
        Http::fake();

        $platform = $this->createPlatform(['lifecycle_policy_enabled' => false]);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'status' => 'completed',
            'end_date' => now()->subDay(),
            'phone' => '254700000002',
        ]);

        $this->artisan('subscriptions:check', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame('completed', $payment->fresh()->status);
        Http::assertNothingSent();
    }

    // ----------------------------------------------------- adopting WP expiry

    public function test_activation_records_the_expiry_wordpress_reports(): void
    {
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform, 'Basic Escort', 1500);
        $client = $this->createClient($platform, 7201);

        // What WordPress actually does: stack onto the remaining cutoff, then round
        // to market-local end of day. Deliberately unequal to now()+30d.
        $wpExpiry = now()->addDays(44)->setTime(20, 59, 59)->timestamp;

        $deal = $this->createPendingDeal($platform, $client, $product);
        $this->fakeProvisioningApis($platform, $client, [], ['escort_expire' => $wpExpiry]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'completed',
            'amount' => 1500,
            'currency' => 'KES',
            'duration' => 'monthly',
        ]);

        app(SubscriptionProvisioningService::class)->activateDeal($deal, [
            'payment' => $payment,
            'duration_days' => 30,
        ]);

        $deal->refresh();
        $payment->refresh();

        $this->assertSame(
            $wpExpiry,
            $deal->expires_at->timestamp,
            'deals.expires_at must be the expiry WordPress reported, not now()+duration.'
        );
        $this->assertSame(
            $wpExpiry,
            $payment->end_date->timestamp,
            'payments.end_date must carry the same value, so nothing can drift.'
        );

        $this->assertDatabaseHas('timeline_events', [
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'deal_activated',
        ]);
    }

    public function test_activation_falls_back_to_the_local_expiry_when_wordpress_reports_none(): void
    {
        // Plugin deploys are manual, so a market may still run a build whose
        // /activate response omits escort_expire. That must not break activation.
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform, 'Basic Escort', 1500);
        $client = $this->createClient($platform, 7202);

        $deal = $this->createPendingDeal($platform, $client, $product);
        $this->fakeProvisioningApis($platform, $client);

        app(SubscriptionProvisioningService::class)->activateDeal($deal, ['duration_days' => 30]);

        $deal->refresh();
        $this->assertSame('active', $deal->status);
        $this->assertEqualsWithDelta(
            now()->addDays(30)->timestamp,
            $deal->expires_at->timestamp,
            60,
            'Without a reported expiry the CRM keeps its own computed value.'
        );
    }

    public function test_activation_rejects_an_implausible_wordpress_expiry(): void
    {
        // A garbled or clock-skewed value must never be able to shorten access.
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform, 'Basic Escort', 1500);
        $client = $this->createClient($platform, 7203);

        $deal = $this->createPendingDeal($platform, $client, $product);
        $this->fakeProvisioningApis($platform, $client, [], [
            'escort_expire' => now()->subYear()->timestamp,
        ]);

        app(SubscriptionProvisioningService::class)->activateDeal($deal, ['duration_days' => 30]);

        $deal->refresh();
        $this->assertTrue(
            $deal->expires_at->isFuture(),
            'An expiry in the past must be rejected in favour of the computed fallback.'
        );
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $deal->expires_at->timestamp, 60);
    }

    // ------------------------------------------------------ repair rounding

    public function test_repair_rounds_the_published_expiry_to_market_end_of_day(): void
    {
        $platform = $this->createPlatform(['timezone' => 'Africa/Nairobi']);
        $product = $this->createProduct($platform, 'Basic Escort', 1500);
        $client = $this->createClient($platform, 7301);

        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'basic',
            'status' => 'active',
            'duration' => 'monthly',
            // A raw mid-afternoon stamp, the shape the old code wrote.
            'expires_at' => now()->addDays(10)->setTime(14, 32, 17),
        ]);

        $columns = app(ActiveSubscriptionProfileRepairService::class)
            ->repairColumnsForDeal($deal, $client);

        $expected = SubscriptionExpiry::endOfDay($deal->expires_at->timestamp, 'Africa/Nairobi');

        $this->assertSame($expected, $columns['escort_expire']);
        $this->assertGreaterThanOrEqual(
            $deal->expires_at->timestamp,
            $columns['escort_expire'],
            'Rounding must only ever move the cutoff later, never shorten a subscription.'
        );
        $this->assertTrue(
            SubscriptionExpiry::isDayBased($columns['escort_expire'], 'Africa/Nairobi'),
            'The published expiry must qualify for end-of-day grace in both the CRM and the theme.'
        );
    }

    public function test_repair_refuses_to_republish_a_profile_held_open_only_by_an_seo_boost(): void
    {
        // An SEO boost is an internal campaign, not purchased access. The reconciler
        // refuses to let one keep a lapsed profile alive, so the repair must refuse
        // to bring one back — otherwise a recovery run over-restores.
        $platform = $this->createPlatform();
        $product = $this->createProduct($platform, 'Basic Escort', 1500);
        $client = $this->createClient($platform, 7401);
        $client->forceFill([
            'profile_status' => 'private',
            'escort_expire' => now()->subDays(3)->timestamp,
        ])->save();

        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'basic',
            'status' => 'active',
            'duration' => 'monthly',
            'origin' => 'seo_boost',
            'expires_at' => now()->addDays(14),
        ]);

        $repair = app(ActiveSubscriptionProfileRepairService::class);

        $this->assertFalse($repair->hasFutureActiveDeal($client->fresh()));
        $this->assertTrue(
            $repair->affectedClients($platform->id)->isEmpty(),
            'A profile held open only by an SEO boost must not appear as a repair candidate.'
        );
    }

    // ---------------------------------------------------------------- helpers

    private function createPlatform(array $overrides = []): Platform
    {
        return Platform::factory()->create(array_merge([
            'name' => 'Kenya Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'timezone' => 'Africa/Nairobi',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ], $overrides));
    }

    private function createProduct(Platform $platform, string $name, float $monthlyPrice): Product
    {
        return Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => $name,
            'display_name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(5),
            'tier' => 'basic',
            'weekly_price' => round($monthlyPrice / 4, 2),
            'biweekly_price' => round($monthlyPrice / 2, 2),
            'monthly_price' => $monthlyPrice,
            'currency' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createClient(Platform $platform, int $wpPostId): Client
    {
        return Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => $wpPostId,
            'wp_user_id' => $wpPostId + 5000,
            'phone_normalized' => '254700'.str_pad((string) $wpPostId, 6, '0', STR_PAD_LEFT),
            'profile_status' => 'private',
            'premium' => false,
            'featured' => false,
            'verified' => false,
        ]);
    }

    private function createPendingDeal(Platform $platform, Client $client, Product $product): Deal
    {
        return Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'basic',
            'amount' => 1500,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'pending',
            'activated_at' => null,
            'expires_at' => null,
            'payment_id' => null,
            'payment_reference' => null,
        ]);
    }

    private function fakeProvisioningApis(
        Platform $platform,
        Client $client,
        array $profileOverrides = [],
        array $activateOverrides = []
    ): void {
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');

        $profilePayload = array_merge([
            'wp_post_id' => (int) $client->wp_post_id,
            'wp_user_id' => (int) $client->wp_user_id,
            'name' => (string) $client->name,
            'phone' => (string) $client->phone_normalized,
            'post_status' => 'publish',
            'premium' => false,
            'featured' => false,
            'verified' => false,
            'premium_expire' => null,
            'featured_expire' => null,
            'escort_expire' => now()->addDays(30)->timestamp,
            'needs_payment' => false,
            'notactive' => false,
            'last_online' => null,
        ], $profileOverrides);

        Http::fake([
            "{$baseUrl}/clients/{$client->wp_post_id}/activate" => Http::response(array_merge([
                'success' => true,
                'crm_deal_id' => null,
            ], $activateOverrides), 200),
            "{$baseUrl}/clients/{$client->wp_post_id}" => Http::response($profilePayload, 200),
            '*' => Http::response([], 200),
        ]);
    }
}
