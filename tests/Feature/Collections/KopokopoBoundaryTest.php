<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionProviderEvent;
use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use App\Models\Payment;
use App\Services\Collections\CollectionReferenceGuard;
use App\Services\Collections\CollectionWriterGuard;
use App\Services\Collections\Kopokopo\KopokopoConnectionService;
use App\Services\Collections\Kopokopo\KopokopoFeedService;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

class KopokopoBoundaryTest extends KopokopoCollectionTestCase
{
    public function test_staff_suffix_case_and_whitespace_cannot_evade_reversed_receipt(): void
    {
        [$feed] = $this->kenya();
        $this->receive($feed, $this->providerBody([], 'reversed-boundary', 'buygoods_transaction_reversed'));
        $deal = new Deal(['platform_id' => 1]);
        foreach ([' ukx fix0001-1 ', 'ukxfix0001/2', 'UKXFIX0001'] as $reference) {
            try {
                app(CollectionReferenceGuard::class)->assertStaffClaim($deal, $reference, 1500);
                $this->fail('Reversed receipt bypassed by '.$reference);
            } catch (\Illuminate\Validation\ValidationException $e) {
                $this->assertStringContainsString('reversed', $e->getMessage());
            }
        }
    }

    public function test_standing_agreement_does_not_block_separate_stk_payment_write(): void
    {
        [$feed, $client, , , $price] = $this->kenya();
        app(CollectionWriterGuard::class)->assertClient($client->id);
        $payment = new Payment(['platform_id' => 1, 'client_id' => $client->id, 'user_id' => $client->wp_user_id,
            'product_id' => $price->product_id, 'amount' => 1500, 'currency' => 'KES', 'phone' => '254700000001',
            'status' => 'pending', 'transaction_reference' => 'STKOTHER001', 'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'raw_payload' => ['source' => 'kopokopo_stk']]);
        $payment->save();
        $payment->update(['status' => 'completed']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertDatabaseCount('collection_receipts', 0);
    }

    public function test_legacy_stk_completion_can_provision_with_a_separate_till_standing_agreement(): void
    {
        [$feed, $client, , , $price] = $this->kenya();
        $payment = Payment::create(['platform_id' => 1, 'client_id' => $client->id, 'user_id' => $client->wp_user_id,
            'product_id' => $price->product_id, 'product_price_id' => $price->id, 'amount' => 1500, 'currency' => 'KES',
            'phone' => '254700000001', 'status' => 'pending', 'transaction_reference' => 'STKOTHER002',
            'purpose' => Payment::PURPOSE_SUBSCRIPTION, 'provider_environment' => 'production', 'raw_payload' => ['source' => 'kopokopo_stk']]);
        $deal = Deal::withoutEvents(fn () => Deal::factory()->create(['client_id' => $client->id, 'platform_id' => 1, 'product_id' => $price->product_id, 'payment_id' => $payment->id]));
        $provisioning = \Mockery::mock(\App\Services\SubscriptionProvisioningService::class);
        $provisioning->shouldReceive('provisionCompletedPayment')->once()->andReturn($deal);
        $this->app->instance(\App\Services\SubscriptionProvisioningService::class, $provisioning);
        $result = app(\App\Services\PaymentCompletionService::class)->complete($payment, ['reference' => 'STKOTHER002', 'amount' => '1500.00', 'currency' => 'KES'], ['client' => $client]);
        $this->assertSame('completed', $result['payment']->status);
        $this->assertNotNull($result['deal'], json_encode($result['payment']->payment_data));
        $this->assertSame($client->id, $result['deal']->client_id);
        $this->assertDatabaseCount('collection_receipts', 0);
        $this->assertDatabaseCount('collection_allocations', 0);
    }

    public function test_sales_can_view_status_but_cannot_manage_and_unsigned_callback_is_refused(): void
    {
        [$feed, , , $actor] = $this->kenya();
        $actor->update(['role' => 'sales', 'assigned_market_ids' => [1]]);
        // The account's market remains explicitly assigned to this staff member.
        Sanctum::actingAs($actor);
        $this->getJson('/api/crm/collections/kopokopo')->assertOk()->assertJsonPath('can_manage', false)->assertJsonMissingPath('feed.callback_url');
        $this->postJson('/api/crm/collections/kopokopo/reconcile')->assertForbidden();
        $this->postJson('/api/collection-events/v1/kopokopo/'.$feed->id, json_decode($this->providerBody(), true))->assertUnauthorized();
        $this->assertDatabaseCount('collection_provider_events', 0);
    }

    public function test_subscription_adoption_verifies_exact_callback_scope_format_and_optimistic_version(): void
    {
        [$feed, , , $actor] = $this->kenya();
        Http::fake(function ($request) use ($feed) {
            if (str_ends_with($request->url(), '/oauth/token')) {
                return Http::response(['access_token' => 'fixture-token']);
            }

            return Http::response(['data' => ['id' => 'existing-subscription', 'type' => 'webhook_subscription', 'attributes' => [
                'event_type' => 'buygoods_transaction_received', 'webhook_uri' => app(KopokopoFeedService::class)->callback($feed),
                'status' => 'Active', 'scope' => 'till', 'scope_reference' => '268491', 'enable_daraja_payload' => false]]]);
        });
        $result = app(KopokopoConnectionService::class)->verifySubscription($feed, $actor, 'buygoods_transaction_received',
            'https://api.kopokopo.com/api/v2/webhook_subscriptions/existing-subscription', $feed->version);
        $this->assertSame('registered', data_get($result->subscriptions, 'buygoods_transaction_received.state'));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && ! str_ends_with($r->url(), '/oauth/token'));
    }

    public function test_paused_capture_cannot_send_a_subscription_request_or_change_registration_state(): void
    {
        [$feed, , , $actor] = $this->kenya();
        $before = $feed->subscriptions;
        config(['collection.capture_enabled' => false]);
        Http::fake();
        try {
            app(KopokopoConnectionService::class)->subscribe($feed, $actor, $feed->version);
            $this->fail('Paused capture sent a provider request');
        } catch (\App\Services\Collections\CollectionException $e) {
            $this->assertSame('provider_capture_disabled', $e->reason);
        }
        $this->assertSame($before, $feed->fresh()->subscriptions);
        Http::assertNothingSent();
    }

    public function test_admin_dismissal_retains_original_body_and_receipt_reversal(): void
    {
        [$feed, , , $actor] = $this->kenya();
        $this->receive($feed, $this->providerBody([], 'reversed-review', 'buygoods_transaction_reversed'));
        $bad = $this->providerBody(['amount' => '1501.00'], 'reversed-review', 'buygoods_transaction_reversed');
        $this->receive($feed, $bad);
        $event = CollectionProviderEvent::where('state', 'quarantined')->firstOrFail();
        $original = $event->raw_body;
        $this->artisan('collections:kopokopo-review', ['event' => $event->id])->assertSuccessful();
        $this->assertSame('quarantined', $event->fresh()->state);
        $this->artisan('collections:kopokopo-review', ['event' => $event->id, '--apply' => true, '--actor' => $actor->id,
            '--expected-sha256' => $event->raw_sha256, '--reason' => 'Reviewed the original provider identity conflict; receipt remains held.'])->assertSuccessful();
        $this->assertSame('reviewed', $event->fresh()->state);
        $this->assertSame($original, $event->fresh()->raw_body);
        $this->assertSame('reversed', CollectionReceipt::firstOrFail()->funds_state);
        $this->assertNotEmpty(CollectionReceipt::firstOrFail()->conflicts);
        $this->assertDatabaseCount('payments', 0);
    }
}
