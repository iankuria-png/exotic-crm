<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Platform;
use Laravel\Sanctum\Sanctum;

class CollectionProfileSearchTest extends CollectionWorkflowTestCase
{
    private function fixture(): array
    {
        [$account, $device, $client, $intent, $actor] = $this->purchase(false);
        config(['collection.workspace_enabled' => true]);
        Platform::whereKey($client->platform_id)->update(['domain' => 'https://www.tz-fixture.test']);
        $client->update(['name' => 'Linzy', 'phone_normalized' => '255711222333', 'email' => 'linzy@example.test',
            'wp_profile_slug' => 'linzy', 'wp_profile_permalink' => null]);
        Sanctum::actingAs($actor);

        return [$client, $this->received($device)];
    }

    private function search($receipt, string $term)
    {
        return $this->getJson('/api/crm/collections/receipts/'.$receipt->id.'/candidates?workflow=manual&search='.urlencode($term))->assertOk();
    }

    public function test_pasted_profile_links_resolve_like_the_clients_page(): void
    {
        [$client, $r] = $this->fixture();
        $this->search($r, 'https://www.tz-fixture.test/escort/linzy/')->assertJsonCount(1, 'clients')
            ->assertJsonPath('clients.0.id', $client->id)->assertJsonPath('clients.0.matched_by', 'profile_link')
            ->assertJsonPath('resolution.mode', 'exact');
        $client->update(['wp_profile_permalink' => 'https://www.tz-fixture.test/escort/linzy/']);
        $this->search($r, 'https://www.tz-fixture.test/escort/linzy')->assertJsonPath('clients.0.id', $client->id)
            ->assertJsonPath('resolution.source', 'stored_permalink');
        $this->search($r, 'https://www.another-market.test/escort/linzy/')->assertJsonCount(0, 'clients')
            ->assertJsonPath('resolution.mode', 'unresolved');
    }

    public function test_ids_local_phones_emails_and_names_match_with_the_reason_shown(): void
    {
        [$client, $r] = $this->fixture();
        $this->search($r, '77')->assertJsonPath('clients.0.id', $client->id)->assertJsonPath('clients.0.matched_by', 'profile_id');
        $this->search($r, '0711 222 333')->assertJsonPath('clients.0.id', $client->id)->assertJsonPath('clients.0.matched_by', 'phone')
            ->assertJsonPath('clients.0.phone', '+255711222333');
        $this->search($r, 'linzy@example.test')->assertJsonPath('clients.0.matched_by', 'email');
        $longer = Client::factory()->create(['platform_id' => $client->platform_id, 'wp_post_id' => 81, 'wp_user_id' => 91, 'name' => 'Linzy Rose', 'is_high_risk' => false]);
        $this->search($r, 'Linzy')->assertJsonCount(2, 'clients')->assertJsonPath('clients.0.id', $client->id)
            ->assertJsonPath('clients.0.matched_by', 'name_exact')->assertJsonPath('clients.1.id', $longer->id)->assertJsonPath('clients.1.matched_by', 'name');
    }

    public function test_profiles_outside_the_receiving_market_or_unselectable_are_not_offered(): void
    {
        [$client, $r] = $this->fixture();
        $other = Platform::factory()->create(['currency_code' => 'TZS']);
        Client::factory()->create(['platform_id' => $other->id, 'wp_post_id' => 82, 'wp_user_id' => 92, 'name' => 'Linzy Elsewhere', 'is_high_risk' => false]);
        Client::factory()->create(['platform_id' => $client->platform_id, 'wp_post_id' => 83, 'wp_user_id' => 93, 'name' => 'Linzy Paused', 'is_high_risk' => false, 'profile_paused_at' => now()]);
        $this->search($r, 'Linzy')->assertJsonCount(1, 'clients')->assertJsonPath('clients.0.id', $client->id);
    }
}
