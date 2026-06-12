<?php

namespace Tests\Feature\CRM;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientCityKeyFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_city_key_matches_grouped_variants(): void
    {
        $platform = Platform::factory()->create();

        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Nairobi']);
        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'nairobi']);
        Client::factory()->create(['platform_id' => $platform->id, 'city' => ' Nairobi ']);
        Client::factory()->create(['platform_id' => $platform->id, 'city' => 'Mombasa']);

        Sanctum::actingAs(User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]));

        $response = $this->getJson("/api/crm/clients?platform_id={$platform->id}&city_key=nairobi");

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }
}
