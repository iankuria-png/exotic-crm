<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionSalesSession;
use App\Models\Deal;
use App\Models\Payment;
use App\Services\Collections\CollectionSalesFulfillmentService;
use App\Services\Collections\CollectionSalesService;
use App\Services\WalletSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class CollectionSalesDiscountTest extends CollectionWorkflowTestCase
{
    private const PIN = '938475';

    private function fixture(): array
    {
        $f = $this->purchase(false);
        [$account, $device, $client, $intent, $actor, $price] = $f;
        $account->update(['mode' => 'shadow']);
        $price->update(['duration_days' => 7, 'duration_key' => 'weekly', 'duration_label' => '7 days']);
        $intent->update(['purchase_snapshot' => array_replace($intent->purchase_snapshot, ['duration_days' => 7, 'duration_key' => 'weekly', 'duration_label' => '7 days'])]);
        config(['collection.workspace_enabled' => true, 'collection_sales.verified_accounts' => [$account->id],
            'collection_sales.verified_platforms' => [$client->platform_id], 'billing.billing_system_live_read.enabled' => false]);
        app(WalletSettingsService::class)->updateDiscountPin(self::PIN, $actor->id);
        app(WalletSettingsService::class)->updateDiscountConfig(['max_percentage_by_platform' => [$client->platform_id => 40]], $actor->id);
        Sanctum::actingAs($actor);
        $receipt = $this->received($device);
        $receipt->update(['principal_minor' => 3000000]);
        $intent->update(['authority' => 'reviewed_quote', 'provider_reference' => $receipt->reference_norm]);

        return [...$f, $receipt];
    }

    private function input($r, $c, $price, array $discount = []): array
    {
        return ['receipt_version' => $r->version, 'session_version' => 0, 'request_key' => (string) Str::uuid(),
            'items' => [['client_id' => $c->id, 'product_price_id' => $price->id,
                'discount' => $discount + ['enabled' => true, 'payable_amount' => '30000', 'percentage' => '14.29', 'pin' => self::PIN]]]];
    }

    public function test_exact_payable_discount_survives_cash_preparation_worker_and_replay_without_pin_persistence(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $input = $this->input($r, $c, $price);
        $sales = app(CollectionSalesService::class);
        $preview = $sales->preview($r, $actor, $input);
        $this->assertTrue($preview['can_activate']);
        $this->assertSame(3000000, $preview['allocated_minor']);
        $this->assertSame('35000.00', $preview['items'][0]['purchase']['original_amount']);
        $this->assertSame('14.29', $preview['items'][0]['purchase']['discount_percentage']);
        $this->assertSame(7, $preview['items'][0]['purchase']['duration_days']);
        $input['preview_token'] = $preview['preview_token'];
        $this->fakeWordPress();
        $fulfillment = app(CollectionSalesFulfillmentService::class);
        $prepared = $fulfillment->prepare($r, $actor, $input);
        $item = CollectionSalesSession::findOrFail($prepared['session']['id'])->items()->firstOrFail();
        $this->assertSame(30000.0, (float) Payment::findOrFail($item->payment_id)->amount);
        $this->assertSame(30000.0, (float) Deal::findOrFail($item->deal_id)->amount);
        $this->assertSame(35000.0, (float) Deal::findOrFail($item->deal_id)->original_amount);
        $this->assertSame($actor->id, Deal::findOrFail($item->deal_id)->discount_approved_by);
        $this->assertSame('agent_manual', Deal::findOrFail($item->deal_id)->discount_source);
        $this->assertSame('confirmed', $fulfillment->resume($item->id)->state);
        $this->assertSame(1, $this->posts);
        $replay = $fulfillment->prepare($r->fresh(), $actor, $input);
        $this->assertSame($prepared['session']['id'], $replay['session']['id']);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('deals', 1);
        $this->assertStringNotContainsString(self::PIN, json_encode(DB::table('collection_audit')->get()));
        $this->assertStringNotContainsString(self::PIN, json_encode($item->fresh()->purchase));
    }

    public function test_invalid_pin_and_over_cap_discount_create_no_cash_or_draft(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $path = '/api/crm/collections/receipts/'.$r->id.'/sales/preview';
        $this->postJson($path, $this->input($r, $c, $price, ['pin' => '000000']))->assertStatus(422)->assertJsonValidationErrors('items.0.discount.pin');
        $this->postJson($path, $this->input($r, $c, $price, ['payable_amount' => '20000']))->assertStatus(422)->assertJsonValidationErrors('items.0.discount.percentage');
        $this->postJson($path, $this->input($r, $c, $price, ['payable_amount' => '35000']))->assertStatus(422)->assertJsonValidationErrors('items.0.discount.payable_amount');
        $this->assertDatabaseCount('collection_sales_sessions', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('deals', 0);
    }

    public function test_rounded_display_cannot_bypass_exact_market_cap(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        app(WalletSettingsService::class)->updateDiscountConfig(['max_percentage_by_platform' => [$c->platform_id => 14.28]], $actor->id);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/sales/preview', $this->input($r, $c, $price))
            ->assertStatus(422)->assertJsonValidationErrors('items.0.discount.percentage');
    }

    public function test_discounted_draft_reopens_with_economic_terms_and_requires_a_fresh_pin(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $input = $this->input($r, $c, $price);
        $saved = app(CollectionSalesService::class)->save($r, $actor, $input);
        $this->assertSame(3000000, $saved['session']['allocated_minor']);
        $this->assertTrue($saved['session']['items'][0]['purchase']['receipt_discount_requested']);
        $this->assertStringNotContainsString(self::PIN, json_encode($saved));
        $input['session_version'] = $saved['session']['version'];
        $input['items'][0]['discount']['pin'] = '';
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/sales/preview', $input)->assertStatus(422)->assertJsonValidationErrors('items.0.discount.pin');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_saved_discount_quote_can_be_explicitly_repriced_from_catalogue_on_the_same_quote_then_confirmed(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $quote = Deal::withoutEvents(fn () => Deal::factory()->create(['platform_id' => $c->platform_id, 'client_id' => $c->id,
            'product_id' => $price->product_id, 'product_price_id' => $price->id, 'plan_type' => 'vip', 'currency' => 'TZS',
            'duration_days' => 7, 'status' => 'pending', 'payment_id' => null, 'activated_at' => null,
            'amount' => '32000.00', 'original_amount' => '35000.00', 'discount_percentage' => '8.57',
            'discount_source' => 'agent_manual', 'discount_approved_by' => $actor->id]));
        $intent->update(['deal_id' => $quote->id, 'purchase_snapshot' => array_replace($intent->purchase_snapshot,
            ['amount_minor' => 3200000, 'original_amount' => '35000.00', 'discount_percentage' => '8.57', 'discount_source' => 'agent_manual', 'discount_approved_by' => $actor->id])]);
        $input = $this->input($r, $c, $price);
        $input['items'][0]['replace_saved_price'] = true;
        $preview = app(CollectionSalesService::class)->preview($r, $actor, $input);
        $this->assertSame($quote->id, $preview['items'][0]['deal_id']);
        $this->assertSame(32000.0, (float) $quote->fresh()->amount); // Preview is pure.
        $input['preview_token'] = $preview['preview_token'];
        $this->fakeWordPress();
        $fulfillment = app(CollectionSalesFulfillmentService::class);
        $prepared = $fulfillment->prepare($r, $actor, $input);
        $item = CollectionSalesSession::findOrFail($prepared['session']['id'])->items()->firstOrFail();
        $this->assertSame($quote->id, $item->deal_id);
        $this->assertSame(30000.0, (float) $quote->fresh()->amount);
        $this->assertSame('confirmed', $fulfillment->resume($item->id)->state);
        $this->assertDatabaseCount('deals', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertNotNull($intent->fresh()->revoked_at);
    }

    public function test_percentage_only_discount_uses_the_existing_money_rounding_and_excess_is_not_a_discount(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $r->update(['principal_minor' => 2800000]);
        $input = $this->input($r, $c, $price, ['payable_amount' => null, 'percentage' => 20]);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/sales/preview', $input)->assertOk()->assertJsonPath('allocated_minor', 2800000);
        $r->update(['principal_minor' => 4000000]);
        $input = $this->input($r, $c, $price, ['enabled' => false]);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/sales/preview', $input)->assertStatus(422)->assertJsonPath('reason', 'allocate_remainder_or_add_finance_reason');
    }

    public function test_policy_change_after_preview_requires_fresh_discount_approval_and_creates_no_cash(): void
    {
        [$a, $device, $c, $intent, $actor, $price, $r] = $this->fixture();
        $input = $this->input($r, $c, $price);
        $input['preview_token'] = app(CollectionSalesService::class)->preview($r, $actor, $input)['preview_token'];
        app(WalletSettingsService::class)->updateDiscountConfig(['max_percentage_by_platform' => [$c->platform_id => 10]], $actor->id);
        $this->postJson('/api/crm/collections/receipts/'.$r->id.'/sales/activate', $input)
            ->assertStatus(422)->assertJsonValidationErrors('items.0.discount.percentage');
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('deals', 0);
        $this->assertDatabaseCount('collection_sales_sessions', 0);
    }
}
