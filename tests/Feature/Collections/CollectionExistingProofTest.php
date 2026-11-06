<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAllocation;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentManualSubmission;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionFulfillmentService;
use Illuminate\Support\Facades\Http;

class CollectionExistingProofTest extends CollectionWorkflowTestCase
{
    public function test_receiver_evidence_for_already_active_proof_verifies_without_extra_cash_days_or_dispatch(): void
    {
        [$account, $device, $client, $intent, $actor] = $this->purchase();
        $account->update(['mode' => 'shadow']);
        $p = $intent->purchase_snapshot;
        $deal = Deal::create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'product_id' => $p['product_id'], 'product_price_id' => $p['product_price_id'],
            'plan_type' => 'vip', 'duration' => 'weekly', 'duration_days' => 8, 'amount' => 35000, 'currency' => 'TZS', 'status' => 'active',
            'activated_at' => now()->subMinute(), 'expires_at' => now()->addDays(8), 'is_free_trial' => false]);
        $payment = Payment::factory()->create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'user_id' => 55, 'escort_post_id' => 77,
            'product_id' => $p['product_id'], 'deal_id' => $deal->id, 'amount' => 35000, 'currency' => 'TZS', 'status' => 'pending', 'purpose' => 'subscription',
            'source' => 'manual_confirmation', 'provider_key' => 'manual_confirmation', 'provider_environment' => 'production', 'record_classification' => 'live',
            'transaction_reference' => 'ABCDEF12345', 'reference_root' => 'ABCDEF12345', 'reference_sequence' => null, 'manual_payment_bundle_id' => null,
            'payment_data' => ['duration_days' => 8, 'product_price_id' => $p['product_price_id']]]);
        $deal->update(['payment_id' => $payment->id]);
        PaymentManualSubmission::create(['payment_id' => $payment->id, 'client_id' => $client->id, 'platform_id' => $client->platform_id, 'product_id' => $p['product_id'],
            'duration_key' => 'custom_8', 'manual_method_key' => 'fixture', 'activated_on_submit' => true, 'sender_name' => 'Fixture payer', 'transaction_reference' => 'ABCDEF12345',
            'destination_snapshot_json' => ['fixture' => true], 'proof_disk' => 'local', 'proof_path' => 'fixture-unused.jpg', 'proof_mime' => 'image/jpeg', 'proof_size_bytes' => 1]);
        $intent->update(['deal_id' => $deal->id, 'payment_id' => $payment->id]);
        $account->update(['mode' => 'auto']);
        $this->remote[77] = $this->snapshot(77, 55, $deal->expires_at->timestamp, 'vip', $deal->id);
        $before = $this->remote[77];
        $this->fakeWordPress();
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $a = CollectionAllocation::firstOrFail();
        $this->assertSame('active_review_pending', app(CollectionFulfillmentService::class)->resume($a->id)->state);
        $this->assertSame(0, $this->posts);
        $this->assertSame($before, $this->remote[77]);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('deals', 1);
        $this->assertSame($payment->id, $a->payment_id);
        $this->assertSame('completed', $payment->fresh()->status);
        $this->assertSame('manual_review', $payment->fresh()->reconciliation_state);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }
}
