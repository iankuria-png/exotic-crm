<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\Ops\OperationsSettingsService;
use App\Services\Ops\OperationsSettingValidationException;
use App\Support\SyncSliceBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The operations settings registry, pinned at the two properties that make it
 * safe to expose live-editable platform tuning to a browser: a value outside
 * its declared bounds is REJECTED naming the bound rather than silently
 * clamped, and each group is writable only by the roles that declare it.
 */
class OperationsSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function service(): OperationsSettingsService
    {
        return app(OperationsSettingsService::class);
    }

    public function test_a_value_above_its_maximum_is_rejected_naming_the_bound(): void
    {
        $this->expectException(OperationsSettingValidationException::class);
        $this->expectExceptionMessage('at most 300 seconds');

        // Not clamped to 300. A clamp would hide that the operator's intent was
        // never honoured.
        $this->service()->update(
            [['key' => 'ops.sync.slice_seconds', 'value' => 5000]],
            null,
            'admin'
        );
    }

    public function test_a_value_below_its_minimum_is_rejected(): void
    {
        $this->expectException(OperationsSettingValidationException::class);
        $this->expectExceptionMessage('at least 30 seconds');

        $this->service()->update([['key' => 'ops.sync.slice_seconds', 'value' => 1]], null, 'admin');
    }

    public function test_a_watch_threshold_above_its_shed_threshold_is_rejected(): void
    {
        $this->expectException(OperationsSettingValidationException::class);
        $this->expectExceptionMessage('must not be above its shed threshold');

        // Otherwise the platform would enter Limp before it ever entered
        // Cautious — discovered during an incident rather than at the form.
        $this->service()->update(
            [['key' => 'ops.threshold.queue_depth.watch', 'value' => 9000]],
            null,
            'admin'
        );
    }

    public function test_an_accepted_change_is_audited_with_before_and_after_state(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $result = $this->service()->update(
            [['key' => 'ops.sync.slice_seconds', 'value' => 120]],
            $admin->id,
            'admin'
        );

        $this->assertSame(1, $result['updated']);
        $this->assertSame(120, $this->service()->integer('ops.sync.slice_seconds'));

        $audit = AuditLog::query()->where('entity_type', 'ops_setting')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertSame(90, $audit->before_state['value']);
        $this->assertSame(120, $audit->after_state['value']);
    }

    public function test_a_tuning_change_takes_effect_without_a_deploy(): void
    {
        $this->assertSame(90, SyncSliceBudget::fromConfig()->maxSeconds());

        $this->service()->update([['key' => 'ops.sync.slice_seconds', 'value' => 120]], null, 'admin');

        // No config:cache, no git pull — the next slice reads the new budget.
        $this->assertSame(120, SyncSliceBudget::fromConfig()->maxSeconds());
    }

    public function test_reset_returns_a_setting_to_its_declared_default(): void
    {
        $service = $this->service();
        $service->update([['key' => 'ops.sync.slice_seconds', 'value' => 200]], null, 'admin');
        $this->assertSame(200, $service->integer('ops.sync.slice_seconds'));

        $service->reset('ops.sync.slice_seconds', null, 'admin');
        $this->assertSame(90, $service->integer('ops.sync.slice_seconds'));
    }

    public function test_a_sub_admin_may_tune_sync_but_not_lanes_or_thresholds(): void
    {
        $service = $this->service();

        $service->update([['key' => 'ops.sync.slice_seconds', 'value' => 100]], null, 'sub_admin');
        $this->assertSame(100, $service->integer('ops.sync.slice_seconds'));

        try {
            $service->update([['key' => 'ops.lanes.fast.max_jobs', 'value' => 50]], null, 'sub_admin');
            $this->fail('A sub_admin must not be able to resize a queue lane.');
        } catch (OperationsSettingValidationException $exception) {
            $this->assertSame(403, $exception->status);
        }
    }

    public function test_an_unknown_key_is_rejected(): void
    {
        $this->expectException(OperationsSettingValidationException::class);

        $this->service()->update([['key' => 'ops.not.a.real.setting', 'value' => 1]], null, 'admin');
    }

    public function test_a_batch_is_validated_whole_before_any_of_it_is_written(): void
    {
        try {
            $this->service()->update([
                ['key' => 'ops.sync.slice_seconds', 'value' => 120],
                ['key' => 'ops.sync.slice_max_pages', 'value' => 9999],
            ], null, 'admin');
            $this->fail('The batch should have been rejected.');
        } catch (OperationsSettingValidationException) {
            // A partially applied batch could leave thresholds inconsistent
            // with each other, so nothing is written until all of it validates.
            $this->assertSame(90, $this->service()->integer('ops.sync.slice_seconds'));
        }
    }

    public function test_the_endpoint_reports_bounds_and_writability_per_group(): void
    {
        $subAdmin = User::factory()->create(['role' => 'sub_admin', 'status' => 'active']);
        Sanctum::actingAs($subAdmin);

        $response = $this->getJson('/api/crm/settings/system-health/operations-settings')->assertOk();

        $groups = collect($response->json('groups'))->keyBy('key');

        $this->assertTrue($groups['sync']['writable']);
        $this->assertFalse($groups['lanes']['writable']);
        $this->assertFalse($groups['thresholds']['writable']);

        $slice = collect($groups['sync']['settings'])->firstWhere('key', 'ops.sync.slice_seconds');
        $this->assertSame(30, $slice['min']);
        $this->assertSame(300, $slice['max']);
        $this->assertSame(90, $slice['default']);
        $this->assertTrue($slice['is_default']);
    }

    public function test_an_out_of_range_write_returns_422_naming_the_key(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->putJson('/api/crm/settings/system-health/operations-settings', [
            'updates' => [['key' => 'ops.sync.slice_seconds', 'value' => 5000]],
        ])
            ->assertStatus(422)
            ->assertJsonPath('key', 'ops.sync.slice_seconds');
    }

    public function test_a_sub_admin_writing_an_admin_only_group_gets_403(): void
    {
        $subAdmin = User::factory()->create(['role' => 'sub_admin', 'status' => 'active']);
        Sanctum::actingAs($subAdmin);

        $this->putJson('/api/crm/settings/system-health/operations-settings', [
            'updates' => [['key' => 'ops.enforcement.enabled', 'value' => true]],
        ])->assertStatus(403);
    }

    public function test_a_stored_value_outside_tightened_bounds_falls_back_to_the_default(): void
    {
        // Simulates bounds being tightened in a later release with a stored
        // override left behind: the out-of-range number must not reach the
        // scheduler.
        app(\App\Services\FeatureSettingsService::class)->set('ops.sync.slice_seconds', 9999);
        Cache::flush();

        $this->assertSame(90, $this->service()->integer('ops.sync.slice_seconds'));
    }

    public function test_a_shed_threshold_above_the_process_ceiling_is_rejected(): void
    {
        // Production was configured watch 26 / shed 100 / ceiling 60 on 4 Sep.
        // The ceiling trips first, so Limp was unreachable on the platform's
        // most important signal — and nothing said so.
        $this->expectException(OperationsSettingValidationException::class);
        $this->expectExceptionMessage('Limp could never be reached');

        $this->service()->update(
            [['key' => 'ops.threshold.php_processes.shed', 'value' => 100]],
            null,
            'admin'
        );
    }

    public function test_a_ceiling_lowered_below_the_shed_threshold_is_rejected(): void
    {
        $this->expectException(OperationsSettingValidationException::class);
        $this->expectExceptionMessage('Limp could never be reached');

        $this->service()->update(
            [['key' => 'ops.threshold.php_processes.ceiling', 'value' => 20]],
            null,
            'admin'
        );
    }

    public function test_a_coherent_process_threshold_set_is_accepted(): void
    {
        $result = $this->service()->update([
            ['key' => 'ops.threshold.php_processes.ceiling', 'value' => 120],
            ['key' => 'ops.threshold.php_processes.shed', 'value' => 100],
            ['key' => 'ops.threshold.php_processes.watch', 'value' => 60],
        ], null, 'admin');

        $this->assertSame(3, $result['updated']);
        $this->assertSame([], array_values(array_filter(
            $this->service()->configurationWarnings(),
            fn (array $warning): bool => $warning['key'] !== 'ops.threshold.php_processes.ceiling_verified'
        )));
    }

    public function test_a_conflict_already_in_the_database_is_reported_rather_than_hidden(): void
    {
        // Bypass validation the way an older release would have, then confirm
        // the board is told about it.
        $features = app(\App\Services\FeatureSettingsService::class);
        $features->set('ops.threshold.php_processes.ceiling', 60);
        $features->set('ops.threshold.php_processes.shed', 100);
        Cache::flush();

        $warnings = $this->service()->configurationWarnings();
        $conflict = collect($warnings)->firstWhere('key', 'ops.threshold.php_processes.shed');

        $this->assertNotNull($conflict, 'The stored conflict must be reported.');
        $this->assertSame('error', $conflict['severity']);
        // The board has to explain the ladder, not just name the fields.
        $this->assertStringContainsString('ladder', $conflict['why']);
        $this->assertStringContainsString('60', $conflict['why']);
        $this->assertStringContainsString('100', $conflict['why']);
        $this->assertNotEmpty($conflict['suggestions'], 'A conflict must come with a way to fix it.');
    }

    public function test_an_unverified_ceiling_is_reported_as_unable_to_escalate(): void
    {
        $warning = collect($this->service()->configurationWarnings())
            ->firstWhere('key', 'ops.threshold.php_processes.ceiling_verified');

        $this->assertNotNull($warning);
        $this->assertSame('info', $warning['severity'], 'An unconfirmed ceiling is worth stating, but it is not a misconfiguration.');
        $this->assertStringContainsString('cannot escalate', $warning['why']);
        $this->assertStringContainsString('entry-process limit', $warning['fix']);
    }

    public function test_every_suggested_fix_actually_passes_validation(): void
    {
        // A one-click fix that the server then rejects would be worse than no
        // button at all, so each suggestion is applied for real.
        $features = app(\App\Services\FeatureSettingsService::class);

        foreach ([[60, 100, 26], [40, 32, 90]] as [$ceiling, $shed, $watch]) {
            $features->set('ops.threshold.php_processes.ceiling', $ceiling);
            $features->set('ops.threshold.php_processes.shed', $shed);
            $features->set('ops.threshold.php_processes.watch', $watch);
            Cache::flush();

            foreach ($this->service()->configurationWarnings() as $warning) {
                foreach ($warning['suggestions'] as $suggestion) {
                    // Re-seed the broken state before each candidate fix.
                    $features->set('ops.threshold.php_processes.ceiling', $ceiling);
                    $features->set('ops.threshold.php_processes.shed', $shed);
                    $features->set('ops.threshold.php_processes.watch', $watch);
                    Cache::flush();

                    $this->service()->update($suggestion['updates'], null, 'admin');

                    $remaining = array_filter(
                        $this->service()->configurationWarnings(),
                        fn (array $w): bool => $w['severity'] === 'error'
                    );

                    $this->assertSame(
                        [],
                        array_values($remaining),
                        sprintf('Applying "%s" should clear the conflict it offers to fix.', $suggestion['label'])
                    );
                }
            }
        }
    }

    public function test_the_ladder_marks_the_rung_that_cannot_be_reached(): void
    {
        $features = app(\App\Services\FeatureSettingsService::class);
        $features->set('ops.threshold.php_processes.ceiling', 60);
        $features->set('ops.threshold.php_processes.shed', 100);
        Cache::flush();

        $ladder = collect($this->service()->processLadder())->keyBy('label');

        $this->assertTrue($ladder['Normal']['reachable']);
        $this->assertTrue($ladder['Cautious']['reachable']);
        $this->assertFalse($ladder['Limp']['reachable'], 'Limp sits above the ceiling and cannot be entered.');
        $this->assertFalse($ladder['Critical']['reachable'], 'The ceiling is unverified, so Critical is off.');
    }
}
