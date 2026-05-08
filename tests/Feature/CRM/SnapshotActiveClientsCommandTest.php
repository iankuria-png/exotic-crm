<?php

namespace Tests\Feature\CRM;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SnapshotActiveClientsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_snapshot_command_counts_distinct_active_clients_from_deals(): void
    {
        $date = Carbon::parse('2026-05-05');
        $platformA = Platform::factory()->create();
        $platformB = Platform::factory()->create();
        $platformC = Platform::factory()->create();
        $productA = Product::factory()->create(['platform_id' => $platformA->id]);
        $productB = Product::factory()->create(['platform_id' => $platformB->id]);
        $productC = Product::factory()->create(['platform_id' => $platformC->id]);

        $clientA = Client::factory()->create(['platform_id' => $platformA->id]);
        $clientB = Client::factory()->create(['platform_id' => $platformB->id]);
        $clientC = Client::factory()->create(['platform_id' => $platformC->id]);

        Deal::factory()->create([
            'platform_id' => $platformA->id,
            'client_id' => $clientA->id,
            'product_id' => $productA->id,
            'status' => 'active',
            'activated_at' => $date->copy()->subDays(2),
            'expires_at' => $date->copy()->addDays(2),
        ]);
        Deal::factory()->create([
            'platform_id' => $platformA->id,
            'client_id' => $clientA->id,
            'product_id' => $productA->id,
            'status' => 'active',
            'activated_at' => $date->copy()->subDay(),
            'expires_at' => $date->copy()->addDays(5),
        ]);
        Deal::factory()->create([
            'platform_id' => $platformB->id,
            'client_id' => $clientB->id,
            'product_id' => $productB->id,
            'status' => 'active',
            'activated_at' => $date->copy()->subDays(5),
            'expires_at' => $date->copy()->subDay(),
        ]);
        Deal::factory()->create([
            'platform_id' => $platformC->id,
            'client_id' => $clientC->id,
            'product_id' => $productC->id,
            'status' => 'active',
            'activated_at' => $date->copy()->subDay(),
            'expires_at' => null,
        ]);

        $this->artisan('crm:snapshot-active-clients', [
            '--date' => $date->toDateString(),
        ])->assertSuccessful();

        $this->assertDatabaseHas('client_active_snapshots', [
            'date' => $date->toDateString(),
            'platform_id' => $platformA->id,
            'count' => 1,
        ]);
        $this->assertDatabaseHas('client_active_snapshots', [
            'date' => $date->toDateString(),
            'platform_id' => $platformB->id,
            'count' => 0,
        ]);
        $this->assertDatabaseHas('client_active_snapshots', [
            'date' => $date->toDateString(),
            'platform_id' => $platformC->id,
            'count' => 1,
        ]);
    }
}
