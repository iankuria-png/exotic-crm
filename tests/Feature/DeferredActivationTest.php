<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\MarketHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A market being down must never cost the CRM a sale.
 *
 * Payment::create runs inside DealController::activate's transaction, so an
 * exception from the WordPress call used to roll the payment away with it: the
 * salesperson had taken money and the CRM kept no record. A market that cannot
 * be reached now parks the deal as paid and queues the activation instead.
 */
class DeferredActivationTest extends TestCase
{
    use RefreshDatabase;

    private const WP_POST_ID = 8801;

    private function platform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'TZ',
            'country' => 'Tanzania',
            'phone_prefix' => '255',
            'currency_code' => 'TZS',
            'wp_api_url' => 'https://tz.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function makeDeal(Platform $platform): Deal
    {
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => self::WP_POST_ID,
            'wp_user_id' => self::WP_POST_ID + 5000,
            'phone_normalized' => '255700123456',
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
            'duration_days' => 7,
            'amount' => 10000,
            'currency' => 'TZS',
            'status' => 'pending',
        ]);
    }

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Sales '.Str::random(4),
            'email' => Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }

    private function gateMarket(Platform $platform): void
    {
        $platform->forceFill([
            'health_status' => MarketHealthService::STATUS_DOMAIN_UNREACHABLE,
            'health_consecutive_failures' => 5,
            'health_checked_at' => now(),
        ])->save();

        Cache::flush();
    }

    public function test_a_market_that_is_down_does_not_destroy_the_payment(): void
    {
        $platform = $this->platform();
        $deal = $this->makeDeal($platform);
        $this->gateMarket($platform);

        // WordPress is genuinely unreachable, not merely gated.
        Http::fake(['*' => Http::response('Error establishing a database connection', 500)]);

        Sanctum::actingAs($this->admin());

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'payment_method' => 'manual',
            'payment_reference' => 'DIBIP2LJOF',
            'duration_days' => 7,
        ]);

        $response->assertStatus(202);
        $this->assertTrue((bool) $response->json('deferred'));

        // The sale survived: a payment exists and the deal is parked as paid.
        $this->assertSame('paid', (string) $deal->fresh()->status);
        $this->assertNotNull($deal->fresh()->activation_deferred_at);
        $this->assertSame(
            1,
            Payment::query()->where('client_id', $deal->client_id)->count(),
            'the payment the salesperson recorded must survive an unreachable market'
        );
    }

    public function test_the_deferral_is_recorded_on_the_deal_timeline(): void
    {
        $platform = $this->platform();
        $deal = $this->makeDeal($platform);
        $this->gateMarket($platform);
        Http::fake(['*' => Http::response('down', 500)]);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'payment_method' => 'manual',
            'payment_reference' => 'REF'.Str::random(6),
            'duration_days' => 7,
        ])->assertStatus(202);

        $this->assertTrue(
            TimelineEvent::query()
                ->where('entity_type', 'deal')
                ->where('entity_id', $deal->id)
                ->where('event_type', 'activation_deferred')
                ->exists()
        );
    }

    public function test_the_retry_sweep_activates_the_deal_once_the_market_answers(): void
    {
        $platform = $this->platform();
        $deal = $this->makeDeal($platform);

        $deal->forceFill([
            'status' => 'paid',
            'activation_deferred_at' => now()->subMinutes(10),
            'activation_attempts' => 1,
        ])->save();

        $platform->forceFill([
            'health_status' => MarketHealthService::STATUS_HEALTHY,
            'health_consecutive_failures' => 0,
        ])->save();
        Cache::flush();

        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake([
            "{$base}/clients/".self::WP_POST_ID.'/activate' => Http::response([
                'success' => true,
                'escort_expire' => now()->addDays(7)->timestamp,
            ], 200),
            '*' => Http::response([], 200),
        ]);

        $this->artisan('crm:retry-deferred-activations')->assertSuccessful();

        $fresh = $deal->fresh();
        $this->assertSame('active', (string) $fresh->status, 'the queued sale must complete on recovery');
        $this->assertNull($fresh->activation_deferred_at, 'a completed deal must leave the queue');
    }

    public function test_the_sweep_leaves_a_still_down_market_queued(): void
    {
        $platform = $this->platform();
        $deal = $this->makeDeal($platform);

        $deal->forceFill([
            'status' => 'paid',
            'activation_deferred_at' => now()->subMinutes(10),
            'activation_attempts' => 1,
        ])->save();

        $this->gateMarket($platform);
        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('crm:retry-deferred-activations')->assertSuccessful();

        $this->assertSame('paid', (string) $deal->fresh()->status);
        $this->assertNotNull($deal->fresh()->activation_deferred_at, 'it must stay queued, not be abandoned');
        // A gated market must not be hammered by the sweep.
        Http::assertNothingSent();
    }

    public function test_a_healthy_market_still_activates_inline_as_before(): void
    {
        $platform = $this->platform();
        $deal = $this->makeDeal($platform);

        $base = rtrim((string) $platform->wp_api_url, '/');
        Http::fake([
            "{$base}/clients/".self::WP_POST_ID.'/activate' => Http::response([
                'success' => true,
                'escort_expire' => now()->addDays(7)->timestamp,
            ], 200),
            '*' => Http::response([], 200),
        ]);

        Sanctum::actingAs($this->admin());

        $response = $this->postJson("/api/crm/deals/{$deal->id}/activate", [
            'payment_method' => 'manual',
            'payment_reference' => 'GOOD'.Str::random(6),
            'duration_days' => 7,
        ]);

        $response->assertOk();
        $this->assertSame('active', (string) $deal->fresh()->status);
    }
}
