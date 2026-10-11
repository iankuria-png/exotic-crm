<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Services\Monetization\ListingEligibility;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ListingOwnerSyncRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_parallel_sync_winner_is_used_with_owner_check_preserved(): void
    {
        $market = Platform::factory()->create();
        $service = new class extends ListingEligibility
        {
            protected function syncOwnerProfile(int $platformId, int $postId): Client
            {
                Client::factory()->create(['platform_id' => $platformId, 'wp_post_id' => $postId, 'wp_user_id' => 77]);
                throw new UniqueConstraintViolationException('mysql', 'insert clients', [], new \Exception('Concurrent insert'));
            }
        };
        $client = $service->assertOwner($market->id, 123, 77);
        $this->assertSame(77, (int) $client->wp_user_id);
        $this->assertSame(1, Client::where('platform_id', $market->id)->where('wp_post_id', 123)->count());
        try {
            $service->assertOwner($market->id, 123, 78);
            $this->fail('Wrong owner must remain denied');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_other_market_profile_does_not_satisfy_race_recovery(): void
    {
        $market = Platform::factory()->create();
        $other = Platform::factory()->create();
        Client::factory()->create(['platform_id' => $other->id, 'wp_post_id' => 123, 'wp_user_id' => 77]);
        $service = new class extends ListingEligibility
        {
            protected function syncOwnerProfile(int $platformId, int $postId): Client
            {
                throw new UniqueConstraintViolationException('mysql', 'insert clients', [], new \Exception('Other unique collision'));
            }
        };
        try {
            $service->assertOwner($market->id, 123, 77);
            $this->fail('Missing same-market profile must remain unavailable');
        } catch (HttpException $e) {
            $this->assertSame(503,$e->getStatusCode());
        }
    }
}
