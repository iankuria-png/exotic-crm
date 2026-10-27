<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentFulfillmentRecoveryService;
use App\Services\PaymentRecoveryEvidencePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentFulfillmentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        User::factory()->create(['role' => 'admin']);
    }

    private function paid(): array
    {
        $m = Platform::factory()->create(['wp_api_url' => 'https://fixture.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture']);
        $c = Client::factory()->create(['platform_id' => $m->id, 'wp_post_id' => 77, 'wp_user_id' => 55, 'profile_status' => 'private']);
        $product = Product::factory()->create(['platform_id' => $m->id, 'name' => 'BASIC', 'tier' => 'basic', 'currency' => 'KES']);
        request()->attributes->set('recovery_verified_beneficiary', ['platform_id' => $m->id, 'wp_user_id' => 55, 'wp_post_id' => 77]);
        $p = Payment::factory()->create(['platform_id' => $m->id, 'client_id' => $c->id, 'user_id' => 55, 'escort_post_id' => 77, 'deal_id' => null, 'product_id' => $product->id, 'purpose' => 'subscription', 'amount' => 750, 'currency' => 'KES', 'status' => 'completed', 'provider_key' => 'kopokopo', 'provider_environment' => 'production', 'record_classification' => 'live', 'payment_data' => ['duration_days' => 7]]);
        request()->attributes->remove('recovery_verified_beneficiary');
        app(PaymentRecoveryEvidencePolicy::class)->observe($p, ['settled_amount' => 750, 'settled_currency' => 'KES', 'completion_policy' => 'allow_completion', 'review_required' => false], ['account' => 'fixture', 'environment' => 'production', 'transaction_id' => 'fixture-paid', 'verification_method' => 'verified_signature']);
        config(['payment_recovery.automatic_platforms' => [$m->id], 'payment_recovery.verified_operation_platforms' => [$m->id]]);

        return [$m, $c, $p];
    }

    public function test_recent_trusted_payment_recovers_full_duration_once(): void
    {
        [$m,$c,$p] = $this->paid();
        $applied = null;
        Http::fake(function ($req) use (&$applied) {
            if ($req->method() === 'GET') {
                return Http::response(['version' => 1, 'operation' => $applied, 'snapshot' => ['fingerprint' => str_repeat('a', 64), 'wp_user_id' => 55, 'meta' => ['escort_expire' => 0]]]);
            }
            $applied = ['state' => 'succeeded', 'operation_id' => $req['operation_id'], 'post_id' => 77, 'escort_expire' => $req['target_expiry'], 'applied_at' => now()->toIso8601String()];

            return Http::response($applied);
        });
        $service = app(PaymentFulfillmentRecoveryService::class);
        $result = $service->resume($p);
        $this->assertSame('succeeded', $result->state);
        $this->assertSame('closed', $p->fresh()->recoveryCase->state);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $p->fresh()->end_date->timestamp, 2);
        $service->resume($p->fresh());
        $this->assertDatabaseCount('deals', 1);
        $this->assertDatabaseCount('payment_fulfillment_operations', 1);
        $this->assertCount(1, Http::recorded(fn ($r) => $r->method() === 'POST'));
    }

    public function test_timeout_requires_operation_lookup_and_never_blindly_extends_again(): void
    {
        [$m,$c,$p] = $this->paid();
        $saved = null;
        $posts = 0;
        Http::fake(function ($req) use (&$saved, &$posts) {
            if ($req->method() === 'GET') {
                return Http::response(['version' => 1, 'operation' => $saved, 'snapshot' => ['fingerprint' => str_repeat('a', 64), 'wp_user_id' => 55, 'meta' => ['escort_expire' => 0]]]);
            }
            $posts++;
            $saved = ['state' => 'succeeded', 'operation_id' => $req['operation_id'], 'post_id' => 77, 'escort_expire' => $req['target_expiry'], 'applied_at' => now()->toIso8601String()];
            throw new \Illuminate\Http\Client\ConnectionException('Synthetic lost response after WP commit');
        });
        $service = app(PaymentFulfillmentRecoveryService::class);
        $first = $service->resume($p);
        $this->assertSame('effect_unknown', $first->state);
        $second = $service->resume($p->fresh());
        $this->assertSame('succeeded', $second->state);
        $this->assertSame(1, $posts);
        $this->assertDatabaseCount('deals', 1);
    }

    public function test_default_gate_and_unverified_purchase_do_not_mutate_wordpress(): void
    {
        [$m,$c,$p] = $this->paid();
        config(['payment_recovery.automatic_platforms' => []]);
        $this->assertNull(app(PaymentFulfillmentRecoveryService::class)->resume($p));
        Http::assertNothingSent();
    }
}
