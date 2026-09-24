<?php

namespace Tests\Feature;

use App\Jobs\RunProfileSlugAliasRepairJob;
use App\Models\Platform;
use App\Models\ProfileSlugAliasRepairRun;
use App\Models\User;
use App\Services\ProfileSlugAliasRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProfileUrlHealthTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://kenya.example.test/wp-json/exotic-crm-sync/v1';

    public function test_admin_sees_the_market_audit_and_its_runs(): void
    {
        [$platform, $admin] = $this->platformAndAdmin();
        Http::fake([self::API.'/profile-slugs/aliases*' => Http::response($this->auditPayload(3))]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/crm/profile-url-health?platform_id={$platform->id}&kind=wrong_target")
            ->assertOk()
            ->assertJsonPath('available', true)
            ->assertJsonPath('audit.summary.urls', 3)
            ->assertJsonPath('audit.items.0.kind', 'wrong_target')
            ->assertJsonPath('runs', []);

        Http::assertSent(fn (HttpRequest $request) => str_contains($request->url(), 'kind=wrong_target'));
    }

    public function test_a_market_without_the_plugin_routes_reports_it_needs_an_update(): void
    {
        [$platform, $admin] = $this->platformAndAdmin();
        Http::fake([self::API.'/profile-slugs/aliases*' => Http::response(['code' => 'rest_no_route', 'message' => 'No route'], 404)]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/crm/profile-url-health?platform_id={$platform->id}")
            ->assertOk()
            ->assertJsonPath('available', false)
            ->assertJsonPath('reason', 'plugin_outdated');
    }

    public function test_starting_a_repair_snapshots_the_audit_and_queues_one_run_per_market(): void
    {
        Queue::fake();
        [$platform, $admin] = $this->platformAndAdmin();
        Http::fake([self::API.'/profile-slugs/aliases*' => Http::response($this->auditPayload(4))]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/profile-url-health/runs', ['platform_id' => $platform->id])
            ->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.target_urls', 4);

        $runId = (int) $response->json('data.id');
        $this->assertSame(4, ProfileSlugAliasRepairRun::query()->find($runId)->audit_summary['urls']);
        Queue::assertPushed(RunProfileSlugAliasRepairJob::class, fn ($job) => $job->runId === $runId);

        $this->postJson('/api/crm/profile-url-health/runs', ['platform_id' => $platform->id])
            ->assertStatus(409);
    }

    public function test_a_healthy_market_has_nothing_to_repair(): void
    {
        Queue::fake();
        [$platform, $admin] = $this->platformAndAdmin();
        Http::fake([self::API.'/profile-slugs/aliases*' => Http::response($this->auditPayload(0))]);
        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/profile-url-health/runs', ['platform_id' => $platform->id])
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_the_job_repairs_slice_by_slice_and_keeps_every_released_alias_as_backup(): void
    {
        Queue::fake();
        [$platform] = $this->platformAndAdmin();
        $run = $this->queuedRun($platform);

        Http::fake([
            self::API.'/profile-slugs/aliases/repair' => Http::sequence()
                ->push([
                    'released' => [
                        ['meta_id' => 11, 'post_id' => 501, 'slug' => 'lisa', 'kind' => 'wrong_target'],
                        ['meta_id' => 12, 'post_id' => 502, 'slug' => 'mary', 'kind' => 'at_risk'],
                    ],
                    'slugs_processed' => 2,
                    'remaining_slugs' => 1,
                    'remaining_aliases' => 1,
                ])
                ->push([
                    'released' => [['meta_id' => 13, 'post_id' => 503, 'slug' => 'nina', 'kind' => 'revivable']],
                    'slugs_processed' => 1,
                    'remaining_slugs' => 0,
                    'remaining_aliases' => 0,
                ]),
        ]);

        $service = app(ProfileSlugAliasRepairService::class);
        (new RunProfileSlugAliasRepairJob($run->id))->handle($service);
        Queue::assertPushed(RunProfileSlugAliasRepairJob::class, 1);
        $this->assertSame(ProfileSlugAliasRepairRun::STATUS_RUNNING, $run->fresh()->status);

        (new RunProfileSlugAliasRepairJob($run->id))->handle($service);

        $run->refresh();
        $this->assertSame(ProfileSlugAliasRepairRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(3, $run->urls_processed);
        $this->assertSame(3, $run->aliases_released);
        $this->assertSame([11, 12, 13], array_column($run->backup, 'meta_id'));
        $this->assertTrue($run->canRestore());
        Http::assertSent(fn (HttpRequest $request) => $request['limit'] === ProfileSlugAliasRepairService::SLICE_SIZE);
    }

    public function test_a_multi_slice_repair_finishes_on_the_sync_queue(): void
    {
        config(['queue.default' => 'sync']);
        [$platform] = $this->platformAndAdmin();
        $run = $this->queuedRun($platform);
        $slice = fn (int $postId, int $remaining) => [
            'released' => [['meta_id' => $postId, 'post_id' => $postId, 'slug' => "slug-{$postId}", 'kind' => 'at_risk']],
            'slugs_processed' => 1,
            'remaining_slugs' => $remaining,
            'remaining_aliases' => $remaining,
        ];
        Http::fake([
            self::API.'/profile-slugs/aliases/repair' => Http::sequence()
                ->push($slice(601, 2))
                ->push($slice(602, 1))
                ->push($slice(603, 0)),
        ]);

        // The next slice runs inline, so the job must have let go of the market lock.
        RunProfileSlugAliasRepairJob::dispatch($run->id);

        $run->refresh();
        $this->assertSame(ProfileSlugAliasRepairRun::STATUS_COMPLETED, $run->status);
        $this->assertSame([601, 602, 603], array_column($run->backup, 'post_id'));
        Http::assertSentCount(3);
    }

    public function test_a_slice_that_releases_nothing_stops_instead_of_looping(): void
    {
        Queue::fake();
        [$platform] = $this->platformAndAdmin();
        $run = $this->queuedRun($platform);
        Http::fake([self::API.'/profile-slugs/aliases/repair' => Http::response([
            'released' => [],
            'slugs_processed' => 5,
            'remaining_slugs' => 7,
            'remaining_aliases' => 9,
        ])]);

        (new RunProfileSlugAliasRepairJob($run->id))->handle(app(ProfileSlugAliasRepairService::class));

        $run->refresh();
        $this->assertSame(ProfileSlugAliasRepairRun::STATUS_COMPLETED, $run->status);
        $this->assertStringContainsString('12 URLs could not be repaired', (string) $run->notes);
        Queue::assertNothingPushed();
    }

    public function test_restore_sends_the_backup_back_with_its_row_ids(): void
    {
        Queue::fake();
        [$platform, $admin] = $this->platformAndAdmin();
        $run = ProfileSlugAliasRepairRun::create([
            'platform_id' => $platform->id,
            'status' => ProfileSlugAliasRepairRun::STATUS_COMPLETED,
            'aliases_released' => 2,
            'backup' => [
                ['meta_id' => 11, 'post_id' => 501, 'slug' => 'lisa', 'kind' => 'wrong_target'],
                ['meta_id' => 12, 'post_id' => 502, 'slug' => 'mary', 'kind' => 'at_risk'],
            ],
        ]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/crm/profile-url-health/runs/{$run->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.status', 'restoring')
            ->assertJsonPath('data.released_by_kind.wrong_target', 1);

        Http::fake([self::API.'/profile-slugs/aliases/restore' => Http::response(['restored' => 2, 'skipped' => 0])]);
        (new RunProfileSlugAliasRepairJob($run->id))->handle(app(ProfileSlugAliasRepairService::class));

        $run->refresh();
        $this->assertSame(ProfileSlugAliasRepairRun::STATUS_RESTORED, $run->status);
        $this->assertSame(2, $run->restored_count);
        $this->assertSame($admin->id, $run->restored_by);
        $this->assertFalse($run->canRestore());
        Http::assertSent(fn (HttpRequest $request) => $request['rows'] === [
            ['meta_id' => 11, 'post_id' => 501, 'slug' => 'lisa'],
            ['meta_id' => 12, 'post_id' => 502, 'slug' => 'mary'],
        ]);

        $this->postJson("/api/crm/profile-url-health/runs/{$run->id}/restore")->assertStatus(422);
    }

    public function test_backup_downloads_as_csv(): void
    {
        [$platform, $admin] = $this->platformAndAdmin();
        $run = ProfileSlugAliasRepairRun::create([
            'platform_id' => $platform->id,
            'status' => ProfileSlugAliasRepairRun::STATUS_COMPLETED,
            'backup' => [['meta_id' => 11, 'post_id' => 501, 'slug' => 'lisa', 'kind' => 'wrong_target']],
        ]);
        Sanctum::actingAs($admin);

        $csv = $this->get("/api/crm/profile-url-health/runs/{$run->id}/backup")->assertOk()->streamedContent();

        $this->assertStringContainsString("meta_id,post_id,old_slug,case\n11,501,lisa,wrong_target", $csv);
    }

    public function test_sales_users_cannot_open_or_run_the_repair(): void
    {
        [$platform] = $this->platformAndAdmin();
        $sales = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$platform->id],
        ]);
        Sanctum::actingAs($sales);

        $this->getJson("/api/crm/profile-url-health?platform_id={$platform->id}")->assertForbidden();
        $this->postJson('/api/crm/profile-url-health/runs', ['platform_id' => $platform->id])->assertForbidden();
    }

    private function queuedRun(Platform $platform): ProfileSlugAliasRepairRun
    {
        return ProfileSlugAliasRepairRun::create([
            'platform_id' => $platform->id,
            'status' => ProfileSlugAliasRepairRun::STATUS_QUEUED,
            'target_urls' => 3,
        ]);
    }

    private function auditPayload(int $urls): array
    {
        return [
            'summary' => [
                'checked_at' => '2026-09-24T10:00:00+00:00',
                'urls' => $urls,
                'aliases' => $urls,
                'kinds' => [
                    'wrong_target' => ['urls' => min(1, $urls), 'aliases' => min(1, $urls)],
                    'revivable' => ['urls' => 0, 'aliases' => 0],
                    'at_risk' => ['urls' => max(0, $urls - 1), 'aliases' => max(0, $urls - 1)],
                ],
                'changes' => ['redirect_to_404' => min(1, $urls), '404_to_redirect' => 0, 'retargeted' => 0, 'unchanged' => max(0, $urls - 1)],
            ],
            'items' => $urls > 0 ? [[
                'kind' => 'wrong_target',
                'slug' => 'lisa',
                'url' => 'https://kenya.example.test/escort/lisa/',
                'before' => ['state' => 'redirect', 'profile' => ['post_id' => 501, 'title' => 'Lisa Queen']],
                'after' => ['state' => 'not_found', 'profile' => null],
                'release' => [['post_id' => 501, 'title' => 'Lisa Queen', 'status' => 'publish']],
            ]] : [],
            'total' => $urls,
            'page' => 1,
            'per_page' => 25,
        ];
    }

    /** @return array{0: Platform, 1: User} */
    private function platformAndAdmin(): array
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => self::API,
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        return [$platform, $admin];
    }
}
