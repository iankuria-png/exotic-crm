<?php

namespace Tests\Feature;

use App\Models\SystemIncident;
use App\Models\User;
use App\Services\Ops\DegradationEvaluator;
use App\Services\Ops\LoadShedder;
use App\Services\Ops\OperationsSettingsService;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The degradation machinery, pinned at the three places where being wrong is
 * expensive: hysteresis (a single noisy sample must not shed real work),
 * capability gating (only opted-in capabilities may ever be paused), and the
 * manual override (which must always expire).
 */
class SystemDegradationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
    }

    /**
     * Build one signal in a given state.
     *
     * @return array<string, mixed>
     */
    private function signal(string $key, float $value, int $watch, int $shed, ?int $ceiling = null, bool $available = true): array
    {
        $state = 'unavailable';

        if ($available) {
            $state = $value >= $shed ? 'shed' : ($value >= $watch ? 'watch' : 'ok');
        }

        return [
            'key' => $key,
            'label' => $key,
            'value' => $available ? $value : null,
            'unit' => 'units',
            'ceiling' => $ceiling,
            'watch' => $watch,
            'shed' => $shed,
            'state' => $state,
            'available' => $available,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $signals
     * @return array<string, mixed>
     */
    private function sample(array $signals): array
    {
        return [
            'sampled_at' => now()->toIso8601String(),
            'signals' => $signals,
            'lanes' => [],
        ];
    }

    private function evaluator(): DegradationEvaluator
    {
        return app(DegradationEvaluator::class);
    }

    public function test_escalation_needs_two_consecutive_breaches(): void
    {
        $evaluator = $this->evaluator();
        $breaching = $this->sample([$this->signal('queue_depth', 3000, 500, 2000)]);

        // One breach is noise. Shedding on it would pause real work every time
        // a nightly batch briefly filled a lane.
        $first = $evaluator->evaluate($breaching);
        $this->assertSame(LoadShedder::LEVEL_NORMAL, $first['level']);

        $second = $evaluator->evaluate($breaching);
        $this->assertSame(LoadShedder::LEVEL_LIMP, $second['level']);
        $this->assertSame('queue_depth', $second['trigger_signal']);
    }

    public function test_recovery_needs_five_clear_samples_and_steps_down_one_level(): void
    {
        $evaluator = $this->evaluator();
        $breaching = $this->sample([$this->signal('queue_depth', 3000, 500, 2000)]);
        $clear = $this->sample([$this->signal('queue_depth', 10, 500, 2000)]);

        $evaluator->evaluate($breaching);
        $this->assertSame(LoadShedder::LEVEL_LIMP, $evaluator->evaluate($breaching)['level']);

        // Recovery is deliberately slower than escalation, so a system
        // oscillating around a threshold settles rather than flapping.
        foreach (range(1, 4) as $ignored) {
            $this->assertSame(LoadShedder::LEVEL_LIMP, $evaluator->evaluate($clear)['level']);
        }

        $fifth = $evaluator->evaluate($clear);
        $this->assertSame(LoadShedder::LEVEL_CAUTIOUS, $fifth['level'], 'Recovery must step down one level, not jump to Normal.');
    }

    public function test_process_ceiling_goes_straight_to_critical(): void
    {
        $evaluator = $this->evaluator();
        $atCeiling = $this->sample([$this->signal('php_processes', 40, 26, 32, 40)]);

        $evaluator->evaluate($atCeiling);

        $this->assertSame(LoadShedder::LEVEL_CRITICAL, $evaluator->evaluate($atCeiling)['level']);
    }

    public function test_an_unavailable_signal_is_ignored_rather_than_read_as_zero(): void
    {
        $assessment = $this->evaluator()->assess([
            $this->signal('php_processes', 0, 26, 32, 40, available: false),
            $this->signal('queue_depth', 10, 500, 2000),
        ]);

        $this->assertSame(LoadShedder::LEVEL_NORMAL, $assessment['level']);
        $this->assertSame([], $assessment['breaching']);
    }

    public function test_unknown_capabilities_fail_open(): void
    {
        Cache::put(LoadShedder::STATE_CACHE_KEY, ['level' => LoadShedder::LEVEL_CRITICAL, 'enforcement' => true]);

        $shedder = app(LoadShedder::class);

        // A typo in a capability string must never disable payments.
        $this->assertTrue($shedder->allows('payments'));
        $this->assertTrue($shedder->allows('auto_optimise'));
        $this->assertTrue($shedder->allows('client_sync'));
    }

    public function test_capabilities_are_shed_at_their_declared_level(): void
    {
        $shedder = app(LoadShedder::class);

        Cache::put(LoadShedder::STATE_CACHE_KEY, ['level' => LoadShedder::LEVEL_CAUTIOUS, 'enforcement' => true]);
        $this->assertFalse($shedder->allows('auto_optimize'));
        $this->assertTrue($shedder->allows('push_campaigns'));

        Cache::put(LoadShedder::STATE_CACHE_KEY, ['level' => LoadShedder::LEVEL_LIMP, 'enforcement' => true]);
        $this->assertFalse($shedder->allows('push_campaigns'));
        $this->assertTrue($shedder->allows('optimize_queue_worker'));

        Cache::put(LoadShedder::STATE_CACHE_KEY, ['level' => LoadShedder::LEVEL_CRITICAL, 'enforcement' => true]);
        $this->assertFalse($shedder->allows('optimize_queue_worker'));
        $this->assertFalse($shedder->allows('heavy_queue_worker'));
    }

    public function test_observe_only_mode_computes_a_level_without_shedding(): void
    {
        // Enforcement is off by default: levels are computed, recorded and
        // alerted, but nothing is actually paused.
        $this->assertFalse(app(OperationsSettingsService::class)->boolean('ops.enforcement.enabled'));

        Cache::put(LoadShedder::STATE_CACHE_KEY, ['level' => LoadShedder::LEVEL_CRITICAL, 'enforcement' => false]);

        $shedder = app(LoadShedder::class);
        $this->assertSame(LoadShedder::LEVEL_CRITICAL, $shedder->level());
        $this->assertTrue($shedder->allows('auto_optimize'));
    }

    public function test_a_missing_cache_key_means_normal_operation(): void
    {
        Cache::flush();

        $shedder = app(LoadShedder::class);
        $this->assertSame(LoadShedder::LEVEL_NORMAL, $shedder->level());
        $this->assertTrue($shedder->allows('auto_optimize'));
    }

    public function test_one_incident_row_per_transition_not_per_sample(): void
    {
        $evaluator = $this->evaluator();
        $breaching = $this->sample([$this->signal('queue_depth', 3000, 500, 2000)]);

        foreach (range(1, 6) as $ignored) {
            $evaluator->evaluate($breaching);
        }

        $this->assertSame(1, SystemIncident::query()->count());

        $incident = SystemIncident::query()->first();
        $this->assertSame(LoadShedder::LEVEL_NORMAL, $incident->from_level);
        $this->assertSame(LoadShedder::LEVEL_LIMP, $incident->to_level);
        $this->assertSame('queue_depth', $incident->trigger_signal);
        $this->assertSame(2000.0, $incident->threshold, 'The row must record what the reading was measured against.');
    }

    public function test_a_forced_level_expires_and_returns_to_the_sampled_level(): void
    {
        $evaluator = $this->evaluator();
        $clear = $this->sample([$this->signal('queue_depth', 10, 500, 2000)]);
        $evaluator->evaluate($clear);

        $evaluator->force(LoadShedder::LEVEL_LIMP, 'Provider incident', 60, null);

        $shedder = app(LoadShedder::class);
        $this->assertSame(LoadShedder::LEVEL_LIMP, $shedder->level());
        $this->assertTrue($shedder->isForced());

        // A forced level with no expiry is how a system ends up shed for a week
        // after an incident nobody closed out.
        $this->travel(61)->minutes();

        $this->assertSame(LoadShedder::LEVEL_NORMAL, app(LoadShedder::class)->level());
        $this->assertFalse(app(LoadShedder::class)->isForced());
    }

    public function test_forcing_a_level_requires_admin_and_an_expiry(): void
    {
        $subAdmin = User::factory()->create(['role' => 'sub_admin', 'status' => 'active']);
        Sanctum::actingAs($subAdmin);

        $this->postJson('/api/crm/settings/system-health/degradation', [
            'level' => 2,
            'reason' => 'Testing',
            'expires_in_minutes' => 30,
        ])->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->postJson('/api/crm/settings/system-health/degradation', [
            'level' => 2,
            'reason' => 'Testing',
        ])->assertStatus(422);

        $this->postJson('/api/crm/settings/system-health/degradation', [
            'level' => 2,
            'reason' => 'Provider incident',
            'expires_in_minutes' => 30,
        ])->assertOk()->assertJsonPath('forced', true);
    }

    public function test_the_vitals_endpoint_reports_a_stale_sampler_rather_than_stale_numbers(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/crm/settings/system-health/vitals')
            ->assertOk()
            ->assertJsonPath('sampler_stale', true)
            ->assertJsonPath('signals', []);
    }

    /**
     * Build the schedule the way the scheduler does, so the skip closures added
     * for load shedding are evaluated exactly as they are in production.
     *
     * @return array<int, Event>
     */
    private function scheduledEvents(): array
    {
        config()->set('queue.default', 'database');

        $schedule = new Schedule();
        $kernel = new \App\Console\Kernel($this->app, $this->app['events']);

        $method = new \ReflectionMethod($kernel, 'schedule');
        $method->setAccessible(true);
        $method->invoke($kernel, $schedule);

        return $schedule->events();
    }

    /**
     * @return array<int, string>
     */
    private function commandsThatWouldRun(): array
    {
        return array_values(array_map(
            fn (Event $event): string => (string) $event->command,
            array_filter($this->scheduledEvents(), fn (Event $event): bool => $event->filtersPass($this->app))
        ));
    }

    private function assertNoCommandMatching(string $needle, array $commands, string $message): void
    {
        foreach ($commands as $command) {
            $this->assertStringNotContainsString($needle, $command, $message);
        }
    }

    public function test_shedding_stops_the_scheduler_forking_gated_tasks(): void
    {
        app(OperationsSettingsService::class)->update(
            [['key' => 'ops.enforcement.enabled', 'value' => true]],
            null,
            'admin'
        );

        Cache::put(LoadShedder::STATE_CACHE_KEY, ['level' => LoadShedder::LEVEL_LIMP, 'enforcement' => true]);

        $commands = $this->commandsThatWouldRun();

        // The saving is in the fork that never happens, so this asserts on the
        // scheduler's own filters rather than on what a job does once running.
        $this->assertNoCommandMatching('crm:run-auto-optimize', $commands, 'Auto optimize must not be forked at Limp.');
        $this->assertNoCommandMatching('crm:ai-briefing', $commands, 'AI briefings must not be forked at Limp.');
        $this->assertNoCommandMatching('crm:run-auto-push', $commands, 'Push campaigns must not be forked at Limp.');
        $this->assertNoCommandMatching('crm:refresh-retention-insights', $commands, 'Retention insights must not be forked at Limp.');
        $this->assertNoCommandMatching('crm:geocode-cities', $commands, 'Geocoding must not be forked at Limp.');

        // Everything that carries revenue or keeps the CRM truthful keeps running.
        $joined = implode(' | ', $commands);
        $this->assertStringContainsString('crm:sample-vitals', $joined, 'The sampler is what notices; it is never shed.');
        $this->assertStringContainsString('crm:sync-clients', $joined, 'Client sync is never shed.');
        $this->assertStringContainsString('crm:check-market-health', $joined, 'Market health is never shed.');
        $this->assertStringContainsString('crm:reconcile-pending-payments', $joined, 'Payments are never shed.');
        $this->assertStringContainsString('--queue=push,alerts,default,kyc-fanout', $joined, 'The fast lane worker keeps running.');
    }

    public function test_the_optimize_and_heavy_workers_are_not_started_at_critical(): void
    {
        app(OperationsSettingsService::class)->update(
            [['key' => 'ops.enforcement.enabled', 'value' => true]],
            null,
            'admin'
        );

        Cache::put(LoadShedder::STATE_CACHE_KEY, ['level' => LoadShedder::LEVEL_CRITICAL, 'enforcement' => true]);

        $joined = implode(' | ', $this->commandsThatWouldRun());

        $this->assertStringNotContainsString('--queue=auto_optimize', $joined);
        $this->assertStringNotContainsString('--queue=heavy', $joined);
        $this->assertStringContainsString('--queue=push,alerts,default,kyc-fanout', $joined, 'The fast lane carries payments and alerts and is never shed.');
        $this->assertStringContainsString('--queue=sync-clients', $joined, 'Client sync is never shed.');
    }

    public function test_observe_only_forks_everything_even_at_critical(): void
    {
        // Enforcement stays off by default, so a computed Critical level
        // changes nothing about what runs — it only shows what would stop.
        Cache::put(LoadShedder::STATE_CACHE_KEY, ['level' => LoadShedder::LEVEL_CRITICAL, 'enforcement' => false]);

        $joined = implode(' | ', $this->commandsThatWouldRun());

        $this->assertStringContainsString('crm:run-auto-optimize', $joined);
        $this->assertStringContainsString('--queue=auto_optimize', $joined);
    }
}
