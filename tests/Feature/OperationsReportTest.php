<?php

namespace Tests\Feature;

use App\Models\SystemIncident;
use App\Models\User;
use App\Services\Ops\LoadShedder;
use App\Services\Ops\OperationsReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The impact summary is what turns observe-only mode from a list of
 * capabilities into evidence somebody can act on, so the arithmetic has to hold
 * up: overlapping incidents must not double-count, an unresolved incident must
 * run to "now" rather than forever, and a capability's cost must be the time
 * spent at or above the level that sheds it.
 */
class OperationsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function incident(int $toLevel, string $startedAgo, ?string $resolvedAgo): SystemIncident
    {
        return SystemIncident::create([
            'from_level' => LoadShedder::LEVEL_NORMAL,
            'to_level' => $toLevel,
            'trigger_signal' => 'queue_depth',
            'trigger_value' => 3000,
            'threshold' => 2000,
            'origin' => SystemIncident::ORIGIN_AUTOMATIC,
            'started_at' => now()->sub($startedAgo),
            'resolved_at' => $resolvedAgo ? now()->sub($resolvedAgo) : null,
        ]);
    }

    private function summary(int $hours = 24): array
    {
        return app(OperationsReportService::class)->summary($hours);
    }

    private function levelSeconds(array $summary, int $level): int
    {
        foreach ($summary['levels'] as $row) {
            if ($row['level'] === $level) {
                return $row['seconds'];
            }
        }

        return 0;
    }

    public function test_time_at_each_level_sums_to_the_window(): void
    {
        $this->incident(LoadShedder::LEVEL_LIMP, '4 hours', '3 hours');

        $summary = $this->summary(24);
        $total = array_sum(array_column($summary['levels'], 'seconds'));

        $this->assertEqualsWithDelta($summary['window_seconds'], $total, 2);
        $this->assertEqualsWithDelta(3600, $this->levelSeconds($summary, LoadShedder::LEVEL_LIMP), 2);
    }

    public function test_overlapping_incidents_are_not_double_counted(): void
    {
        // Escalating Normal -> Cautious -> Limp leaves both rows open at once,
        // so naively summing per level would report more elapsed time than the
        // window contains.
        $this->incident(LoadShedder::LEVEL_CAUTIOUS, '4 hours', '2 hours');
        $this->incident(LoadShedder::LEVEL_LIMP, '3 hours', '2 hours');

        $summary = $this->summary(24);

        $this->assertEqualsWithDelta(3600, $this->levelSeconds($summary, LoadShedder::LEVEL_CAUTIOUS), 2, 'Cautious should be the hour before Limp began.');
        $this->assertEqualsWithDelta(3600, $this->levelSeconds($summary, LoadShedder::LEVEL_LIMP), 2);
        $this->assertEqualsWithDelta(
            $summary['window_seconds'],
            array_sum(array_column($summary['levels'], 'seconds')),
            2
        );
    }

    public function test_an_unresolved_incident_runs_to_now_not_forever(): void
    {
        $this->incident(LoadShedder::LEVEL_CRITICAL, '2 hours', null);

        $summary = $this->summary(24);

        $this->assertEqualsWithDelta(7200, $this->levelSeconds($summary, LoadShedder::LEVEL_CRITICAL), 5);
        $this->assertLessThanOrEqual($summary['window_seconds'], array_sum(array_column($summary['levels'], 'seconds')));
    }

    public function test_capability_cost_is_time_at_or_above_the_level_that_sheds_it(): void
    {
        $this->incident(LoadShedder::LEVEL_CAUTIOUS, '3 hours', '1 hour');

        $capabilities = collect($this->summary(24)['capabilities'])->keyBy('capability');

        // auto_optimize sheds at Cautious, push_campaigns only at Limp.
        $this->assertEqualsWithDelta(7200, $capabilities['auto_optimize']['seconds'], 5);
        $this->assertSame(0, $capabilities['push_campaigns']['seconds']);
        $this->assertSame(1, $capabilities['auto_optimize']['episodes']);
    }

    public function test_separate_stretches_count_as_separate_episodes(): void
    {
        $this->incident(LoadShedder::LEVEL_LIMP, '6 hours', '5 hours');
        $this->incident(LoadShedder::LEVEL_LIMP, '3 hours', '2 hours');

        $capabilities = collect($this->summary(24)['capabilities'])->keyBy('capability');

        $this->assertSame(2, $capabilities['push_campaigns']['episodes']);
        $this->assertEqualsWithDelta(7200, $capabilities['push_campaigns']['seconds'], 5);
    }

    public function test_a_quiet_window_reports_all_normal_and_nothing_paused(): void
    {
        $summary = $this->summary(24);

        $this->assertEqualsWithDelta($summary['window_seconds'], $this->levelSeconds($summary, LoadShedder::LEVEL_NORMAL), 2);
        $this->assertSame(0, array_sum(array_column($summary['capabilities'], 'seconds')));
        $this->assertSame(0, $summary['transitions']);
    }

    public function test_incidents_outside_the_window_are_clipped_out(): void
    {
        $this->incident(LoadShedder::LEVEL_CRITICAL, '40 hours', '39 hours');

        $summary = $this->summary(24);

        $this->assertSame(0, $this->levelSeconds($summary, LoadShedder::LEVEL_CRITICAL));
    }

    public function test_the_summary_endpoint_is_readable_by_sub_admins(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sub_admin', 'status' => 'active']));

        $this->getJson('/api/crm/settings/system-health/operations-summary?hours=24')
            ->assertOk()
            ->assertJsonPath('window_hours', 24)
            ->assertJsonStructure(['levels', 'capabilities', 'enforcement_enabled']);
    }

    public function test_the_summary_endpoint_is_closed_to_sales(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        $this->getJson('/api/crm/settings/system-health/operations-summary')->assertForbidden();
    }
}
