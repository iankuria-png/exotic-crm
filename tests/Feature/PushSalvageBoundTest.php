<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Services\PushCampaign\PushCampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The salvage loop has to end somewhere.
 *
 * SendPushNotificationJob bounds one dispatch with $tries = 3, but the
 * scheduled dispatcher salvages items stuck in 'scheduled' back to 'pending'
 * every 15 minutes and re-dispatches them with a fresh attempt counter. The
 * item-level dispatch count is what stops an item that can never send from
 * being retried for as long as its campaign runs.
 */
class PushSalvageBoundTest extends TestCase
{
    use RefreshDatabase;

    private function platform(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Market '.Str::random(4),
            'country' => 'Kenya',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
        ]);
    }

    private function campaign(Platform $platform): PushCampaign
    {
        return PushCampaign::query()->create([
            'platform_id' => $platform->id,
            'name' => 'Campaign '.Str::random(4),
            'status' => 'running',
            'message' => 'hello',
            'scheduled_at' => now()->subHours(2),
        ]);
    }

    private function stuckItem(PushCampaign $campaign, int $dispatchAttempts): PushCampaignItem
    {
        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://example.test/p/'.Str::random(5),
            'profile_name' => 'Profile',
            'custom_message' => 'hello',
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinutes(2),
            'dispatch_attempts' => $dispatchAttempts,
        ]);

        // Stale enough for salvage to consider it.
        PushCampaignItem::query()->where('id', $item->id)
            ->update(['updated_at' => now()->subMinutes(30)]);

        return $item->fresh();
    }

    public function test_an_item_under_the_cap_is_salvaged_back_to_pending(): void
    {
        Queue::fake();
        $campaign = $this->campaign($this->platform());
        $item = $this->stuckItem($campaign, 1);

        app(PushCampaignService::class)->queueRunningCampaignPendingItems($campaign);

        $this->assertNotSame('failed', (string) $item->fresh()->status);
    }

    public function test_an_item_at_the_cap_is_failed_instead_of_salvaged_again(): void
    {
        Queue::fake();
        $campaign = $this->campaign($this->platform());
        $item = $this->stuckItem($campaign, 5);

        app(PushCampaignService::class)->queueRunningCampaignPendingItems($campaign);

        $fresh = $item->fresh();
        $this->assertSame('failed', (string) $fresh->status, 'the cycle must end');
        $this->assertStringContainsString('dispatch attempts', (string) $fresh->error_message);
    }

    public function test_dispatching_an_item_counts_the_attempt(): void
    {
        Queue::fake();
        $campaign = $this->campaign($this->platform());

        $item = PushCampaignItem::query()->create([
            'campaign_id' => $campaign->id,
            'profile_url' => 'https://example.test/p/'.Str::random(5),
            'profile_name' => 'Profile',
            'custom_message' => 'hello',
            'status' => 'pending',
            'scheduled_at' => now()->subMinutes(1),
            'dispatch_attempts' => 0,
        ]);

        app(PushCampaignService::class)->queueRunningCampaignPendingItems($campaign);

        $this->assertSame(
            1,
            (int) $item->fresh()->dispatch_attempts,
            'without counting the dispatch the cap can never be reached'
        );
    }
}
