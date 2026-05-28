<?php

namespace Tests\Feature\Seo;

use App\Jobs\ProcessBulkBioBatchJob;
use App\Models\Client;
use App\Models\Platform;
use App\Models\SeoBioBatch;
use App\Models\SeoBioBatchRow;
use App\Models\User;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\LinkCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * End-to-end happy-path tests for the bulk bio generation pipeline:
 *   preview → store → job runs → review → accept.
 */
class BulkBioBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.seo_engine.enabled' => true]);

        // Link catalog stub — we don't want WP calls during bulk tests
        $stub = \Mockery::mock(LinkCatalogService::class);
        $stub->shouldReceive('forPlatform')->andReturn([]);
        $this->app->instance(LinkCatalogService::class, $stub);
    }

    public function test_preview_endpoint_returns_resolved_summary(): void
    {
        $platform = Platform::factory()->create();
        Client::factory()->create([
            'platform_id'     => $platform->id,
            'wp_post_id'      => 42,
            'wp_profile_slug' => 'mira',
            'name'            => 'Mira',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->postJson('/api/crm/seo/bulk/preview', [
            'platform_id' => $platform->id,
            'content'     => "mira\nnot-a-real-slug",
        ]);

        $response->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.resolved', 1)
            ->assertJsonPath('summary.unresolved', 1);
    }

    public function test_preview_with_no_recognizable_rows_returns_422(): void
    {
        $platform = Platform::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->postJson('/api/crm/seo/bulk/preview', [
            'platform_id' => $platform->id,
            'content'     => "    \n\n     \n",
        ]);

        $response->assertStatus(422);
    }

    public function test_store_creates_batch_and_dispatches_job(): void
    {
        Bus::fake();

        $platform = Platform::factory()->create();
        Client::factory()->create([
            'platform_id'     => $platform->id,
            'wp_post_id'      => 1,
            'wp_profile_slug' => 'alpha',
            'name'            => 'Alpha',
        ]);
        Client::factory()->create([
            'platform_id'     => $platform->id,
            'wp_post_id'      => 2,
            'wp_profile_slug' => 'beta',
            'name'            => 'Beta',
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->postJson('/api/crm/seo/bulk', [
            'platform_id' => $platform->id,
            'content'     => "alpha\nbeta",
            'language'    => 'fr',
            'auto_save_to_wp' => false,
        ]);

        $response->assertCreated()
            ->assertJsonPath('batch.total_rows', 2)
            ->assertJsonPath('batch.language', 'fr')
            ->assertJsonPath('batch.status', 'queued');

        Bus::assertDispatched(ProcessBulkBioBatchJob::class);

        $this->assertDatabaseCount('seo_bio_batch_rows', 2);
    }

    public function test_full_job_run_generates_bios_and_marks_ready(): void
    {
        // Stub the LLM provider
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'A generated bio for the profile.']]],
                'usage'   => ['prompt_tokens' => 50, 'completion_tokens' => 25],
            ], 200),
        ]);
        config([
            'services.seo_engine.enabled'         => true,
            'services.seo_engine.providers'       => ['deepseek'],
            'services.seo_engine.deepseek.api_key' => 'sk-test',
            'services.seo_engine.deepseek.model'   => 'deepseek-chat',
        ]);

        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id'     => $platform->id,
            'wp_post_id'      => 7,
            'wp_profile_slug' => 'gamma',
            'name'            => 'Gamma',
        ]);

        $batch = SeoBioBatch::create([
            'platform_id' => $platform->id,
            'language'    => 'en',
            'status'      => SeoBioBatch::STATUS_QUEUED,
            'total_rows'  => 1,
            'generation_options' => [],
        ]);
        SeoBioBatchRow::create([
            'batch_id'   => $batch->id,
            'row_index'  => 1,
            'input_text' => 'gamma',
            'input_url'  => null,
            'wp_post_id' => 7,
            'client_id'  => $client->id,
            'profile_name' => 'Gamma',
            'status'     => SeoBioBatchRow::STATUS_QUEUED,
        ]);

        // Execute job synchronously
        (new ProcessBulkBioBatchJob($batch->id))->handle(app(BioGenerationService::class));

        $batch->refresh();
        $this->assertSame(SeoBioBatch::STATUS_READY, $batch->status);
        $this->assertSame(1, $batch->succeeded_rows);
        $this->assertSame(0, $batch->failed_rows);

        $row = $batch->rows()->first();
        $this->assertSame(SeoBioBatchRow::STATUS_GENERATED, $row->status);
        $this->assertNotNull($row->bio_html);
        $this->assertNotNull($row->score);
    }

    public function test_show_endpoint_returns_batch_with_rows(): void
    {
        $platform = Platform::factory()->create();
        $batch = SeoBioBatch::create([
            'platform_id' => $platform->id,
            'language'    => 'en',
            'status'      => SeoBioBatch::STATUS_READY,
            'total_rows'  => 1,
        ]);
        SeoBioBatchRow::create([
            'batch_id'   => $batch->id,
            'row_index'  => 1,
            'input_text' => 'foo',
            'status'     => SeoBioBatchRow::STATUS_GENERATED,
            'bio_html'   => '<p>Hello</p>',
            'score'      => 75,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->getJson("/api/crm/seo/bulk/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('batch.id', $batch->id)
            ->assertJsonPath('rows.0.bio_html', '<p>Hello</p>')
            ->assertJsonPath('rows.0.score', 75);
    }

    public function test_cancel_endpoint_sets_status_and_skips_queued_rows(): void
    {
        $platform = Platform::factory()->create();
        $batch = SeoBioBatch::create([
            'platform_id' => $platform->id,
            'status'      => SeoBioBatch::STATUS_PROCESSING,
            'total_rows'  => 2,
        ]);
        SeoBioBatchRow::create(['batch_id' => $batch->id, 'row_index' => 1, 'status' => 'queued']);
        SeoBioBatchRow::create(['batch_id' => $batch->id, 'row_index' => 2, 'status' => 'generated', 'bio_html' => 'x', 'score' => 50]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->postJson("/api/crm/seo/bulk/{$batch->id}/cancel")
            ->assertOk()
            ->assertJsonPath('batch.status', 'cancelled');

        $this->assertSame('skipped', $batch->rows()->where('row_index', 1)->first()->status);
        // Generated row left alone
        $this->assertSame('generated', $batch->rows()->where('row_index', 2)->first()->status);
    }

    public function test_destroy_endpoint_removes_batch_and_rows(): void
    {
        $platform = Platform::factory()->create();
        $batch = SeoBioBatch::create(['platform_id' => $platform->id, 'status' => 'ready', 'total_rows' => 1]);
        SeoBioBatchRow::create(['batch_id' => $batch->id, 'row_index' => 1, 'status' => 'generated']);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->deleteJson("/api/crm/seo/bulk/{$batch->id}")->assertOk();
        $this->assertDatabaseMissing('seo_bio_batches', ['id' => $batch->id]);
        $this->assertDatabaseMissing('seo_bio_batch_rows', ['batch_id' => $batch->id]);
    }

    public function test_disabled_engine_blocks_preview_and_store(): void
    {
        config(['services.seo_engine.enabled' => false]);
        $platform = Platform::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->postJson('/api/crm/seo/bulk/preview', [
            'platform_id' => $platform->id,
            'content' => 'foo',
        ])->assertStatus(403);

        $this->postJson('/api/crm/seo/bulk', [
            'platform_id' => $platform->id,
            'content' => 'foo',
        ])->assertStatus(403);
    }
}
