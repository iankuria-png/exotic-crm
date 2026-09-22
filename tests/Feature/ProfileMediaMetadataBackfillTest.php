<?php

namespace Tests\Feature;

use App\Jobs\RunProfileMediaMetadataBackfillJob;
use App\Models\Client;
use App\Models\Platform;
use App\Models\ProfileMediaMetadataBackfillRun;
use App\Models\User;
use App\Services\ProfileMediaMetadataBackfillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileMediaMetadataBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_preview_and_queue_selected_profiles_without_touching_unlinked_clients(): void
    {
        Queue::fake();
        [$platform, $admin] = $this->platformAndAdmin();
        $linked = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 61783]);
        $unlinked = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 0]);

        Sanctum::actingAs($admin);

        $payload = [
            'platform_id' => $platform->id,
            'scope' => ProfileMediaMetadataBackfillRun::SCOPE_SELECTED,
            'client_ids' => [$linked->id, $unlinked->id],
        ];

        $this->postJson('/api/crm/clients/media-metadata-backfill/preview', $payload)
            ->assertOk()
            ->assertJsonPath('summary.profiles', 1)
            ->assertJsonPath('summary.skipped', 1);

        $response = $this->postJson('/api/crm/clients/media-metadata-backfill/runs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.scope', 'selected')
            ->assertJsonPath('data.status', 'queued');

        $runId = (int) $response->json('data.id');
        $this->assertDatabaseHas('profile_media_metadata_backfill_runs', [
            'id' => $runId,
            'platform_id' => $platform->id,
            'status' => 'queued',
        ]);
        Queue::assertPushed(RunProfileMediaMetadataBackfillJob::class, fn ($job) => $job->runId === $runId);
    }

    public function test_backfill_job_processes_a_bounded_slice_and_reports_attachment_totals(): void
    {
        Queue::fake();
        [$platform] = $this->platformAndAdmin();
        $first = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 61783, 'name' => 'Mia']);
        $second = Client::factory()->create(['platform_id' => $platform->id, 'wp_post_id' => 61784, 'name' => 'Nia']);
        $run = ProfileMediaMetadataBackfillRun::query()->create([
            'platform_id' => $platform->id,
            'scope' => ProfileMediaMetadataBackfillRun::SCOPE_SELECTED,
            'client_ids' => [$first->id, $second->id],
            'status' => ProfileMediaMetadataBackfillRun::STATUS_QUEUED,
        ]);

        $baseUrl = rtrim($platform->wp_api_url, '/');
        Http::fake([
            "{$baseUrl}/clients/61783/media/metadata" => Http::response(['attachments_updated' => 2], 200),
            "{$baseUrl}/clients/61784/media/metadata" => Http::response(['attachments_updated' => 0], 200),
        ]);

        (new RunProfileMediaMetadataBackfillJob($run->id))
            ->handle(app(ProfileMediaMetadataBackfillService::class));

        $run->refresh();
        $this->assertSame(ProfileMediaMetadataBackfillRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->candidate_count);
        $this->assertSame(2, $run->processed_count);
        $this->assertSame(2, $run->attachments_updated_count);
        $this->assertSame(1, $run->skipped_count);
        $this->assertSame(0, $run->failed_count);
        Http::assertSentCount(2);
    }

    public function test_sales_user_cannot_start_a_market_wide_metadata_backfill(): void
    {
        [$platform] = $this->platformAndAdmin();
        $sales = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);

        Sanctum::actingAs($sales);

        $this->postJson('/api/crm/clients/media-metadata-backfill/runs', [
            'platform_id' => $platform->id,
            'scope' => ProfileMediaMetadataBackfillRun::SCOPE_MARKET,
        ])->assertForbidden();
    }

    /** @return array{0: Platform, 1: User} */
    private function platformAndAdmin(): array
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://kenya.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        return [$platform, $admin];
    }
}
