<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionReceipt;
use App\Models\Deal;
use Laravel\Sanctum\Sanctum;

class KopokopoReferenceSuggestionTest extends KopokopoCollectionTestCase
{
    public function test_protected_provider_payer_can_suggest_a_code_without_exposing_phone_or_creating_a_purchase(): void
    {
        [$feed, $client, $intent, $actor, $price] = $this->kenya();
        $this->receive($feed);
        $receipt = CollectionReceipt::firstOrFail();
        $receipt->update(['candidate_snapshot' => null]);
        $deal = Deal::factory()->create(['client_id' => $client->id, 'platform_id' => 1, 'currency' => 'KES', 'amount' => 1500,
            'product_id' => $price->product_id, 'product_price_id' => $price->id]);
        Sanctum::actingAs($actor);
        $result = $this->getJson('/api/crm/collections/clients/'.$client->id.'/reference-suggestions?deal_id='.$deal->id)
            ->assertOk()->assertJsonPath('items.0.sender_name', 'Fixture Payer')->assertJsonPath('items.0.reference', 'UKXFIX0001')
            ->assertJsonPath('items.0.possible_profiles.0.id', $client->id)->assertJsonPath('items.0.use_code_available', true)
            ->assertJsonPath('items.0.timezone', 'Africa/Nairobi');
        $this->assertArrayNotHasKey('payer_fingerprint', $result->json('items.0'));
        $this->assertArrayNotHasKey('phone', $result->json('items.0'));
        $this->assertNull($receipt->fresh()->candidate_snapshot);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('collection_purchase_intents', 1); // Original fixture intent only.
    }
}
