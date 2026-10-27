<?php

namespace Tests\Feature;

use App\Jobs\RetryUnlinkedPaymentClientJob;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\PaymentClientIdentityService;
use App\Services\PaymentMatchingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentClientIdentityRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        User::factory()->create(['role' => 'admin']);
    }

    private function payment(Platform $market, array $extra = []): Payment
    {
        return Payment::factory()->create(array_merge(['platform_id' => $market->id, 'product_id' => null,
            'user_id' => 36696, 'client_id' => null, 'deal_id' => null, 'escort_post_id' => null,
            'status' => 'completed', 'purpose' => 'subscription', 'provider_environment' => 'production',
            'record_classification' => 'live', 'phone' => '254700000001', 'amount' => 750,
            'raw_payload' => ['provisioning_status' => 'client_unresolved']], $extra));
    }

    public function test_late_cache_identity_is_linked_without_provisioning_or_payment_state_changes(): void
    {
        $market = Platform::factory()->create();
        $payment = $this->payment($market);
        (new RetryUnlinkedPaymentClientJob($market->id))->handle(app(PaymentClientIdentityService::class));
        $this->assertNull($payment->fresh()->client_id);
        $client = Client::factory()->create(['platform_id' => $market->id, 'wp_user_id' => 36696, 'phone_normalized' => '254700000999']);
        $payment->attempts()->delete(); // Simulate the next due retry after cache arrival.
        $before = $payment->fresh()->getAttributes();
        (new RetryUnlinkedPaymentClientJob($market->id))->handle(app(PaymentClientIdentityService::class));
        $after = $payment->fresh()->getAttributes();
        $this->assertSame($client->id, $after['client_id']);
        $this->assertSame('manual_review', $after['reconciliation_state']);
        foreach (['amount', 'status', 'deal_id', 'completed_at', 'updated_at', 'raw_payload'] as $key) {
            $this->assertSame($before[$key], $after[$key], $key);
        }
        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseHas('audit_log', ['action' => 'payment_client_identity_recovery', 'entity_id' => $payment->id]);
        Http::assertNothingSent();
        (new RetryUnlinkedPaymentClientJob($market->id))->handle(app(PaymentClientIdentityService::class));
        $this->assertSame(1, \App\Models\AuditLog::where('action', 'payment_client_identity_recovery')->count());
    }

    public function test_existing_free_trial_is_preserved_and_fulfillment_review_stays_open(): void
    {
        $market = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $market->id, 'wp_user_id' => 36696]);
        $deal = \App\Models\Deal::factory()->create(['platform_id' => $market->id, 'client_id' => $client->id,
            'is_free_trial' => true, 'status' => 'active', 'expires_at' => now()->addWeek()]);
        $payment = $this->payment($market);
        $before = $deal->fresh()->getAttributes();
        (new RetryUnlinkedPaymentClientJob($market->id))->handle(app(PaymentClientIdentityService::class));
        $this->assertSame($client->id, $payment->fresh()->client_id);
        $this->assertNull($payment->fresh()->deal_id);
        $this->assertSame('manual_review', $payment->fresh()->reconciliation_state);
        $this->assertSame($before, $deal->fresh()->getAttributes());
        $this->assertDatabaseCount('deals', 1);
        $this->assertDatabaseCount('wallet_transactions', 0);
        $this->assertDatabaseCount('client_monetization_passes', 0);
        Http::assertNothingSent();
    }

    public function test_zero_value_trial_payments_are_not_recovered_as_paid_receipts(): void
    {
        $market = Platform::factory()->create();
        Client::factory()->create(['platform_id' => $market->id, 'wp_user_id' => 36696]);
        $payment = $this->payment($market, ['amount' => 0]);
        (new RetryUnlinkedPaymentClientJob($market->id))->handle(app(PaymentClientIdentityService::class));
        $this->assertNull($payment->fresh()->client_id);
        $this->assertDatabaseCount('audit_log', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_wordpress_sync_dispatches_market_recovery_after_cache_arrives(): void
    {
        $market = Platform::factory()->create(['wp_api_url' => 'https://identity-sync.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture']);
        $payment = $this->payment($market, ['escort_post_id' => 113275]);
        Http::fake(['https://identity-sync.example.test/wp-json/exotic-crm-sync/v1/clients/113275' => Http::response([
            'wp_post_id' => 113275, 'wp_user_id' => 36696, 'name' => 'Synthetic cache arrival', 'post_status' => 'publish',
        ])]);
        $client = (new \App\Services\ClientSyncService($market))->syncOne(113275);
        Queue::assertPushed(RetryUnlinkedPaymentClientJob::class, fn ($job) => $job->platformId === $market->id && $job->afterCommit === true);
        $this->assertNull($payment->fresh()->client_id);
        (new RetryUnlinkedPaymentClientJob($market->id))->handle(app(PaymentClientIdentityService::class));
        $this->assertSame($client->id, $payment->fresh()->client_id);
        $this->assertDatabaseCount('deals', 0);
    }

    public function test_missing_wp_identity_does_not_fall_through_to_a_different_phone_owner(): void
    {
        $market = Platform::factory()->create();
        Client::factory()->create(['platform_id' => $market->id, 'wp_user_id' => 44, 'phone_normalized' => '254700000001']);
        $payment = $this->payment($market);
        $service = app(PaymentMatchingService::class);
        $preview = $service->dryRunMatchPayment($payment);
        $this->assertFalse($preview['matched']);
        $this->assertNull($payment->fresh()->client_id);
        $this->assertFalse($service->matchPayment($payment)['matched']);
        $this->assertNull($payment->fresh()->client_id);
    }

    public function test_ambiguous_conflicting_cross_market_and_visitor_identities_are_not_linked(): void
    {
        $market = Platform::factory()->create();
        $other = Platform::factory()->create();
        Client::factory()->create(['platform_id' => $other->id, 'wp_user_id' => 36696]);
        $payment = $this->payment($market);
        $service = app(PaymentClientIdentityService::class);
        $this->assertFalse($service->resolve($payment)['matched']);
        $one = Client::factory()->create(['platform_id' => $market->id, 'wp_user_id' => 36696, 'wp_post_id' => 77]);
        $two = Client::factory()->create(['platform_id' => $market->id, 'wp_user_id' => 55, 'wp_post_id' => 88]);
        $payment->escort_post_id = 88;
        $this->assertFalse($service->resolve($payment)['matched']);
        $payment->escort_post_id = 77;
        $this->assertSame($one->id, $service->resolve($payment)['client']->id);
        $two->update(['wp_user_id' => 36696]);
        $this->assertFalse($service->resolve($payment)['matched']);
        $payment->purpose = Payment::PURPOSE_VISITOR_CONTACT_UNLOCK;
        $this->assertSame('excluded', $service->resolve($payment)['confidence']);
    }
}
