<?php

namespace Tests\Feature\AutoOptimize;

use App\Models\AutoOptimizeAlert;
use App\Models\AutoOptimizeItem;
use App\Models\AutoOptimizePlan;
use App\Models\AutoOptimizeRun;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\AutoOptimize\AutoOptimizeApplyService;
use App\Services\AutoOptimize\AutoOptimizeConfig;
use App\Services\AutoOptimize\AutoOptimizeWriteLedger;
use App\Services\AutoOptimize\SystemActorResolver;
use App\Services\ClientProfileImageService;
use App\Services\Seo\SeoScorer;
use App\Services\WpSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AutoOptimizeApplyServiceTest extends TestCase
{
    use RefreshDatabase;

    private Platform $platform;
    private AutoOptimizePlan $plan;
    private AutoOptimizeRun $run;
    private Client $client;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        SystemActorResolver::forget();
        Cache::flush();

        $this->platform = Platform::query()->create([
            'name' => 'Test Kenya',
            'domain' => 'test.example',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'wp_api_url' => 'https://test.example/wp-json',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
        ]);

        $this->plan = AutoOptimizePlan::query()->create([
            'name' => 'Test Plan',
            'platform_id' => $this->platform->id,
            'enabled' => false,
            'reliability' => ['min_score_gain' => 3, 'max_writes_per_hour' => 100, 'min_image_gain' => 0.10],
        ]);

        $this->run = AutoOptimizeRun::query()->create([
            'auto_optimize_plan_id' => $this->plan->id,
            'platform_id' => $this->platform->id,
            'status' => 'running',
        ]);

        $this->client = Client::factory()->create([
            'platform_id' => $this->platform->id,
            'wp_post_id' => 101,
            'seo_score' => 40,
        ]);

        $this->adminUser = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin+' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);
    }

    public function test_bio_only_apply_writes_bio_and_score(): void
    {
        $item = $this->makeItem([
            'new_bio_html' => '<p>New bio content here</p>',
            'new_score' => 75,
            'new_score_breakdown' => ['word_count' => 20, 'links' => 20, 'completeness' => 20, 'media' => 15],
            'previous_score' => 40,
            'previous_bio_html' => '<p>Old bio</p>',
            'source_bio_hash' => md5('<p>Old bio</p>'),
            'profile_snapshot' => $this->makeSnapshotArray(),
        ]);

        $wpMock = $this->mockWpWithBioHash('<p>Old bio</p>');
        $wpMock->expects($this->once())->method('updateClientProfile')
            ->with(101, ['content' => '<p>New bio content here</p>']);
        $wpMock->expects($this->once())->method('writeSeoScore');
        $wpMock->expects($this->never())->method('setClientMainImage');

        $service = $this->makeApplyService($wpMock);
        $result = $service->apply($item, $this->adminUser);

        $this->assertSame('applied', $result->status);
        $this->assertTrue($result->actions_applied['bio']);
        $this->assertArrayNotHasKey('image', $result->actions_applied);
        $this->assertSame(md5('<p>New bio content here</p>'), $result->applied_bio_hash);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'auto_optimize_item', 'entity_id' => $item->id]);
    }

    public function test_image_only_apply_writes_image_and_score(): void
    {
        $item = $this->makeItem([
            'new_bio_html' => null, // no bio staged
            'new_main_attachment_id' => 999,
            'new_main_image_url' => 'https://cdn.example/new.jpg',
            'previous_main_attachment_id' => 888,
            'previous_score' => 40,
            'previous_score_breakdown' => ['word_count' => 20, 'links' => 20, 'completeness' => 20, 'media' => 10],
            'profile_snapshot' => $this->makeSnapshotArray(),
        ]);

        $wpMock = $this->mockWp();
        $wpMock->expects($this->once())->method('setClientMainImage')->with(101, 999);
        $wpMock->expects($this->once())->method('writeSeoScore');
        $wpMock->expects($this->never())->method('updateClientProfile');

        $service = $this->makeApplyService($wpMock);
        $result = $service->apply($item, $this->adminUser);

        $this->assertSame('applied', $result->status);
        $this->assertArrayNotHasKey('bio', $result->actions_applied);
        $this->assertTrue($result->actions_applied['image']);
        $this->assertNull($result->applied_bio_hash); // bio not applied
    }

    public function test_below_score_gain_skips_bio_action(): void
    {
        // new_score 42 - previous 40 = +2, min_gain = 3 → bio skipped
        $item = $this->makeItem([
            'new_bio_html' => '<p>Slightly better bio</p>',
            'new_score' => 42,
            'previous_score' => 40,
            'profile_snapshot' => $this->makeSnapshotArray(),
        ]);

        $wpMock = $this->mockWp();
        $wpMock->expects($this->never())->method('updateClientProfile');
        $wpMock->expects($this->never())->method('writeSeoScore');

        $service = $this->makeApplyService($wpMock);
        $result = $service->apply($item, $this->adminUser);

        $this->assertSame('skipped', $result->status);
    }

    public function test_source_changed_conflict_skips_bio(): void
    {
        $item = $this->makeItem([
            'new_bio_html' => '<p>New bio</p>',
            'new_score' => 75,
            'previous_score' => 40,
            'source_bio_hash' => md5('original bio'),
            'profile_snapshot' => $this->makeSnapshotArray(),
        ]);

        // WP returns a different bio — conflict
        $wpMock = $this->mockWpWithBioHash('somebody changed it');
        $wpMock->expects($this->never())->method('updateClientProfile');

        $service = $this->makeApplyService($wpMock);
        $result = $service->apply($item, $this->adminUser);

        $this->assertSame('skipped', $result->status);
    }

    public function test_autopilot_apply_uses_system_actor(): void
    {
        $item = $this->makeItem([
            'new_bio_html' => '<p>New bio</p>',
            'new_score' => 75,
            'previous_score' => 40,
            'source_bio_hash' => md5('<p>Old bio</p>'),
            'profile_snapshot' => $this->makeSnapshotArray(),
        ]);

        $wpMock = $this->mockWpWithBioHash('<p>Old bio</p>');
        $wpMock->method('updateClientProfile')->willReturn([]);
        $wpMock->method('writeSeoScore')->willReturn([]);

        $service = $this->makeApplyService($wpMock);
        $result = $service->apply($item, null); // no approver → system actor

        $this->assertSame('applied', $result->status);
        $systemUserId = User::where('email', 'automation+auto-optimize@system.local')->value('id');
        $this->assertSame($systemUserId, $result->approved_by);
    }

    public function test_system_actor_missing_fails_closed(): void
    {
        // Delete system user to simulate misconfiguration
        User::where('email', 'automation+auto-optimize@system.local')->delete();
        SystemActorResolver::forget();

        $item = $this->makeItem([
            'new_bio_html' => '<p>New bio</p>',
            'new_score' => 75,
            'previous_score' => 40,
        ]);

        $wpMock = $this->mockWp();

        $service = $this->makeApplyService($wpMock);
        $result = $service->apply($item, null);

        // Should fail, not write an audit row with a guessed id
        $this->assertSame('failed', $result->status);
        $this->assertDatabaseMissing('audit_log', ['entity_id' => $item->id, 'action' => 'auto_optimize_applied']);
    }

    public function test_revert_restores_previous_bio_and_score(): void
    {
        $item = $this->makeItem([
            'status' => 'applied',
            'previous_bio_html' => '<p>Old bio</p>',
            'new_bio_html' => '<p>New bio</p>',
            'applied_bio_hash' => md5('<p>New bio</p>'),
            'previous_score' => 40,
            'previous_score_breakdown' => ['word_count' => 10, 'links' => 10, 'completeness' => 10, 'media' => 10],
            'new_score' => 75,
            'actions_applied' => ['bio' => true, 'score' => true],
            'profile_snapshot' => $this->makeSnapshotArray(),
        ]);

        $wpMock = $this->mockWpWithBioHash('<p>New bio</p>'); // current matches applied_bio_hash
        $wpMock->expects($this->once())->method('updateClientProfile')
            ->with(101, ['content' => '<p>Old bio</p>']);
        $wpMock->expects($this->once())->method('writeSeoScore')->with(101, 40, $this->anything());

        $service = $this->makeApplyService($wpMock);
        $result = $service->revert($item, $this->adminUser);

        $this->assertSame('reverted', $result->status);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'auto_optimize_item', 'entity_id' => $item->id, 'action' => 'auto_optimize_reverted']);
    }

    public function test_image_only_revert_does_not_touch_bio(): void
    {
        $item = $this->makeItem([
            'status' => 'applied',
            'previous_main_attachment_id' => 888,
            'new_main_attachment_id' => 999,
            'previous_score' => 40,
            'previous_score_breakdown' => ['word_count' => 10, 'links' => 10, 'completeness' => 10, 'media' => 10],
            'actions_applied' => ['image' => true, 'score' => true],
            'profile_snapshot' => $this->makeSnapshotArray(),
        ]);

        $wpMock = $this->mockWp();
        $wpMock->expects($this->once())->method('setClientMainImage')->with(101, 888);
        $wpMock->expects($this->once())->method('writeSeoScore');
        $wpMock->expects($this->never())->method('updateClientProfile');

        $service = $this->makeApplyService($wpMock);
        $result = $service->revert($item, $this->adminUser);

        $this->assertSame('reverted', $result->status);
    }

    public function test_revert_conflict_throws_without_force(): void
    {
        $item = $this->makeItem([
            'status' => 'applied',
            'previous_bio_html' => '<p>Old bio</p>',
            'applied_bio_hash' => md5('<p>Our bio</p>'),
            'actions_applied' => ['bio' => true],
            'profile_snapshot' => $this->makeSnapshotArray(),
        ]);

        // WP returns something different from applied_bio_hash
        $wpMock = $this->mockWpWithBioHash('human edited this');

        $service = $this->makeApplyService($wpMock);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/revert_conflict/');
        $service->revert($item, $this->adminUser, force: false);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function makeItem(array $attrs): AutoOptimizeItem
    {
        return AutoOptimizeItem::query()->create(array_merge([
            'auto_optimize_plan_id' => $this->plan->id,
            'auto_optimize_run_id' => $this->run->id,
            'platform_id' => $this->platform->id,
            'client_id' => $this->client->id,
            'status' => 'pending',
        ], $attrs));
    }

    private function makeSnapshotArray(): array
    {
        return [
            'client_id' => $this->client->id,
            'wp_post_id' => 101,
            'platform_id' => $this->platform->id,
            'name' => 'Test Client',
            'age' => 25,
            'city' => 'Nairobi',
            'neighborhood' => null,
            'gender' => 'female',
            'ethnicity' => null,
            'build' => null,
            'height' => null,
            'hair_color' => null,
            'services' => [],
            'languages' => ['English'],
            'rates' => [],
            'availability' => null,
            'existing_bio' => '<p>Old bio</p>',
            'media_summary' => ['image_count' => 3, 'video_count' => 0, 'has_main_image' => true],
        ];
    }

    private function mockWp(): WpSyncService
    {
        $mock = $this->createMock(WpSyncService::class);
        $mock->method('getClientProfile')->willReturn(['content' => '<p>Old bio</p>', 'main_image_attachment_id' => 888]);
        $mock->method('updateClientProfile')->willReturn([]);
        $mock->method('writeSeoScore')->willReturn([]);
        $mock->method('setClientMainImage')->willReturn([]);
        $this->app->instance(WpSyncService::class, $mock);
        return $mock;
    }

    private function mockWpWithBioHash(string $currentBio): WpSyncService
    {
        $mock = $this->createMock(WpSyncService::class);
        $mock->method('getClientProfile')->willReturn(['content' => $currentBio]);
        $mock->method('updateClientProfile')->willReturn([]);
        $mock->method('writeSeoScore')->willReturn([]);
        $mock->method('setClientMainImage')->willReturn([]);
        $this->app->instance(WpSyncService::class, $mock);
        return $mock;
    }

    private function makeApplyService(WpSyncService $wpMock): AutoOptimizeApplyService
    {
        $factory = $this->createMock(\App\Services\WpSyncFactory::class);
        $factory->method('forPlatform')->willReturn($wpMock);

        return new AutoOptimizeApplyService(
            $factory,
            app(SeoScorer::class),
            app(ClientProfileImageService::class),
            app(\App\Services\AutoOptimize\AutoOptimizeAlertService::class),
            app(AutoOptimizeWriteLedger::class),
        );
    }
}
