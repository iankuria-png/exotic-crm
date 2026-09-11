<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use App\Services\SubscriptionProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A completed WordPress activation must survive anything that happens after it.
 *
 * activateDeal publishes the advertiser on WordPress first and does every local
 * write afterwards, and three of its callers wrap the whole thing in a database
 * transaction. Before the guard under test, a WordPress read failing during the
 * post-activation client sync threw straight out of a successful activation —
 * the transaction rolled back, and the advertiser was left live and paid-for
 * with the CRM believing the deal was still pending.
 */
class SubscriptionActivationOrphanTest extends TestCase
{
    use RefreshDatabase;

    private const WP_POST_ID = 4242;

    private function platform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Kenya Market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function deal(Platform $platform): Deal
    {
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => self::WP_POST_ID,
            'wp_user_id' => self::WP_POST_ID + 5000,
            'phone_normalized' => '254700123456',
            'profile_status' => 'private',
        ]);

        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'VIP',
            'display_name' => 'VIP',
            'slug' => 'vip-'.Str::random(5),
            'tier' => 'vip',
        ]);

        return Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'plan_type' => 'vip',
            'duration' => 'weekly',
            'status' => 'pending',
        ]);
    }

    /**
     * WordPress accepts the activation, then dies before the follow-up read.
     * That is the exact production shape: activate succeeds, the market's
     * database then fails, and the profile GET comes back 500.
     */
    private function fakeActivationThenDeadMarket(Platform $platform): void
    {
        $base = rtrim((string) $platform->wp_api_url, '/');

        Http::fake([
            "{$base}/clients/".self::WP_POST_ID.'/activate' => Http::response([
                'success' => true,
                'escort_expire' => now()->addDays(7)->timestamp,
            ], 200),
            "{$base}/clients/".self::WP_POST_ID => Http::response(
                '<html><body>Error establishing a database connection</body></html>',
                500
            ),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_a_failed_post_activation_sync_no_longer_undoes_the_activation(): void
    {
        $platform = $this->platform();
        $deal = $this->deal($platform);
        $this->fakeActivationThenDeadMarket($platform);

        $activated = app(SubscriptionProvisioningService::class)->activateDeal($deal, [
            'payment_method' => 'manual',
        ]);

        // The activation stands, in the returned model and in the database.
        $this->assertSame('active', (string) $activated->status);
        $this->assertSame('active', (string) $deal->fresh()->status);
        $this->assertNotNull($deal->fresh()->activated_at);
        $this->assertNotNull($deal->fresh()->expires_at);
    }

    public function test_the_activation_reached_wordpress_before_the_sync_failed(): void
    {
        $platform = $this->platform();
        $deal = $this->deal($platform);
        $this->fakeActivationThenDeadMarket($platform);

        app(SubscriptionProvisioningService::class)->activateDeal($deal, [
            'payment_method' => 'manual',
        ]);

        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::assertSent(fn ($request) => $request->url() === "{$base}/clients/".self::WP_POST_ID.'/activate');
    }

    public function test_the_returned_deal_still_carries_its_client_when_the_sync_fails(): void
    {
        $platform = $this->platform();
        $deal = $this->deal($platform);
        $this->fakeActivationThenDeadMarket($platform);

        $activated = app(SubscriptionProvisioningService::class)->activateDeal($deal, [
            'payment_method' => 'manual',
        ]);

        // fresh(['client', ...]) re-reads from the database, so a failed remote
        // refresh must not leave the caller holding a null relation.
        $this->assertNotNull($activated->client);
        $this->assertSame(self::WP_POST_ID, (int) $activated->client->wp_post_id);
    }

    public function test_a_committed_wordpress_activation_is_reported_so_an_orphan_can_be_named(): void
    {
        $platform = $this->platform();
        $deal = $this->deal($platform);
        $this->fakeActivationThenDeadMarket($platform);

        $service = app(SubscriptionProvisioningService::class);

        $this->assertFalse(
            $service->wordPressActivationCommitted((int) $deal->id),
            'nothing has been activated yet'
        );

        $service->activateDeal($deal, ['payment_method' => 'manual']);

        $this->assertTrue(
            $service->wordPressActivationCommitted((int) $deal->id),
            'the caller must be able to tell an orphan from a clean failure'
        );
    }

    public function test_a_market_that_is_down_outright_still_fails_the_activation(): void
    {
        $platform = $this->platform();
        $deal = $this->deal($platform);

        // The activation itself fails. Nothing was committed on WordPress, so
        // this must still raise — the guard protects a *completed* activation,
        // it does not swallow a failed one.
        Http::fake(['*' => Http::response('gateway down', 502)]);

        $this->expectException(\Throwable::class);

        try {
            app(SubscriptionProvisioningService::class)->activateDeal($deal, [
                'payment_method' => 'manual',
            ]);
        } finally {
            $this->assertSame('pending', (string) $deal->fresh()->status);
        }
    }
}
