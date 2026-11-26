<?php

namespace Tests\Feature;

use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Deal;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Collections\CollectionWorkflowTestCase;

class DealManualExtensionReferenceOrderTest extends CollectionWorkflowTestCase
{
    private function fixture(): array
    {
        [$account, $device, $client, $intent, $actor, $price] = $this->purchase(false);
        $account->update(['mode' => 'shadow']);
        $receipt = $this->received($device);
        CollectionPurchaseIntent::query()->delete();
        $deal = Deal::factory()->create(['platform_id' => $client->platform_id, 'client_id' => $client->id,
            'product_id' => $price->product_id, 'product_price_id' => $price->id, 'amount' => 35000, 'currency' => 'TZS',
            'status' => 'active', 'plan_type' => 'vip', 'duration' => 'weekly', 'duration_days' => 7,
            'activated_at' => now()->subDays(2), 'expires_at' => now()->addDays(5)]);
        Sanctum::actingAs($actor);

        return [$client, $receipt, $deal];
    }

    private function extend($deal, $receipt)
    {
        return $this->postJson('/api/crm/deals/'.$deal->id.'/extend', ['payment_method' => 'manual',
            'payment_reference' => $receipt->reference_norm, 'additional_days' => 7, 'reason' => 'Fixture verified renewal']);
    }

    public function test_reversed_and_insufficient_receipts_are_refused_before_any_wordpress_request(): void
    {
        [$client, $receipt, $deal] = $this->fixture();
        $expiry = $deal->expires_at->toIso8601String();
        foreach ([['funds_state' => 'reversed'], ['funds_state' => 'observed', 'principal_minor' => 100]] as $values) {
            $receipt->update($values);
            $this->extend($deal, $receipt)->assertUnprocessable()->assertJsonValidationErrors('payment_reference');
            Http::assertNothingSent();
            $this->assertDatabaseCount('payments', 0);
            $this->assertSame($expiry, $deal->fresh()->expires_at->toIso8601String());
        }
    }

    public function test_newly_used_code_is_refused_before_wordpress_dispatch(): void
    {
        [$client, $receipt, $deal] = $this->fixture();
        Payment::withoutEvents(fn () => Payment::factory()->create(['platform_id' => $client->platform_id, 'client_id' => $client->id,
            'reference_root' => $receipt->reference_norm, 'transaction_reference' => $receipt->reference_norm, 'reference_number' => $receipt->reference_norm,
            'amount' => 35000, 'currency' => 'TZS', 'status' => 'completed']));
        $response = $this->extend($deal, $receipt);
        $this->assertContains($response->status(), [409, 422]);
        Http::assertNothingSent();
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_valid_extension_has_a_prepared_payment_before_site_call_and_second_attempt_never_dispatches(): void
    {
        [$client, $receipt, $deal] = $this->fixture();
        $extensions = 0;
        Http::fake(function ($request) use ($client, $receipt, &$extensions) {
            if (str_ends_with($request->url(), '/extend')) {
                $extensions++;
                $this->assertSame(1, Payment::where('transaction_reference', $receipt->reference_norm)->count());

                return Http::response(['success' => true, 'escort_expire' => now()->addDays(12)->timestamp]);
            }

            return Http::response(['wp_post_id' => $client->wp_post_id, 'wp_user_id' => $client->wp_user_id, 'name' => $client->name,
                'phone' => $client->phone_normalized, 'email' => $client->email, 'post_status' => 'publish', 'escort_expire' => now()->addDays(12)->timestamp]);
        });
        $this->extend($deal, $receipt)->assertOk();
        $this->assertSame(1, $extensions);
        $this->assertDatabaseCount('payments', 1);
        $this->assertNotNull($deal->fresh()->payment_id);
        $this->assertContains($this->extend($deal, $receipt)->status(), [409, 422]);
        $this->assertSame(1, $extensions);
    }
}
