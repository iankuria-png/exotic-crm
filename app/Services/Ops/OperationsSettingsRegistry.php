<?php

namespace App\Services\Ops;

/**
 * Every operations knob, declared once with its bounds.
 *
 * The registry — not the UI and not a controller — is the source of truth for
 * what a setting means, what it may be set to, and who may set it. A form that
 * hard-codes a range the server does not also enforce is a form that eventually
 * disagrees with the server; here the same declaration renders the field and
 * validates the write.
 *
 * Config values are the DEFAULTS, not the values. A stored setting always wins,
 * which is what makes a tuning change take effect on the next scheduler tick
 * rather than on the next deploy.
 */
class OperationsSettingsRegistry
{
    public const TYPE_INTEGER = 'integer';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_TIME = 'time';

    public const GROUP_SYNC = 'sync';
    public const GROUP_LANES = 'lanes';
    public const GROUP_THRESHOLDS = 'thresholds';
    public const GROUP_ALERTS = 'alerts';

    /**
     * Groups in display order, with the roles allowed to write each.
     *
     * Lane sizing and degradation thresholds are admin-only: a bad value there
     * stalls the queue or sheds real work. Sync pacing and alert routing are
     * safe enough for a sub_admin to tune while an incident is in progress.
     *
     * @return array<string, array{label:string, description:string, write_roles:array<int,string>}>
     */
    public function groups(): array
    {
        return [
            self::GROUP_SYNC => [
                'label' => 'Sync & scheduler',
                'description' => 'How much work one client-sync slice may do before it pauses and re-queues itself.',
                'write_roles' => ['admin', 'sub_admin'],
            ],
            self::GROUP_LANES => [
                'label' => 'Queue lanes',
                'description' => 'Per-lane worker sizing. These take effect on the next scheduler tick.',
                'write_roles' => ['admin'],
            ],
            self::GROUP_THRESHOLDS => [
                'label' => 'Limp mode thresholds',
                'description' => 'When the platform is considered under pressure, and whether crossing a threshold actually sheds load.',
                'write_roles' => ['admin'],
            ],
            self::GROUP_ALERTS => [
                'label' => 'Alert routing',
                'description' => 'Where a degradation transition is announced, and when the phone is allowed to ring.',
                'write_roles' => ['admin', 'sub_admin'],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    /** @var array<string, array<string, mixed>>|null */
    private ?array $definitions = null;

    public function all(): array
    {
        return $this->definitions ??= $this->build();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function inGroup(string $group): array
    {
        return array_filter($this->all(), fn (array $definition): bool => $definition['group'] === $group);
    }

    public function canWriteGroup(string $group, ?string $role): bool
    {
        $groups = $this->groups();

        if (! isset($groups[$group]) || $role === null) {
            return false;
        }

        return in_array($role, $groups[$group]['write_roles'], true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function build(): array
    {
        $definitions = [];

        $add = function (array $definition) use (&$definitions): void {
            $definitions[$definition['key']] = $definition + [
                'type' => self::TYPE_INTEGER,
                'min' => null,
                'max' => null,
                'unit' => null,
                'risk' => 'low',
                'description' => '',
            ];
        };

        // ---- Sync & scheduler -------------------------------------------------
        $add([
            'key' => 'ops.sync.slice_seconds',
            'group' => self::GROUP_SYNC,
            'label' => 'Slice budget',
            'description' => 'Wall-clock a single client-sync slice may spend before it saves its cursor and re-queues.',
            'default' => max(15, (int) config('services.client_sync.slice_seconds', 90)),
            'min' => 30,
            'max' => 300,
            'unit' => 'seconds',
            'risk' => 'medium',
        ]);
        $add([
            'key' => 'ops.sync.slice_max_pages',
            'group' => self::GROUP_SYNC,
            'label' => 'Slice page limit',
            'description' => 'Pages of WordPress profiles one slice may pull, whichever limit it hits first.',
            'default' => max(1, (int) config('services.client_sync.slice_max_pages', 25)),
            'min' => 1,
            'max' => 200,
            'unit' => 'pages',
        ]);
        $add([
            'key' => 'ops.sync.delta_max_platforms',
            'group' => self::GROUP_SYNC,
            'label' => 'Markets per delta run',
            'description' => 'How many markets one paced delta sync rotates through.',
            'default' => max(1, (int) config('services.client_sync.delta_max_platforms_per_run', 3)),
            'min' => 1,
            'max' => 20,
            'unit' => 'markets',
        ]);
        $add([
            'key' => 'ops.sync.delta_stagger_seconds',
            'group' => self::GROUP_SYNC,
            'label' => 'Delta stagger',
            'description' => 'Pause between markets in a delta sync, so 55 WordPress sites are not hit at once.',
            'default' => max(0, (int) config('services.client_sync.delta_stagger_seconds', 120)),
            'min' => 0,
            'max' => 600,
            'unit' => 'seconds',
        ]);
        $add([
            'key' => 'ops.sync.reconcile_stagger_seconds',
            'group' => self::GROUP_SYNC,
            'label' => 'Reconcile stagger',
            'description' => 'Pause between markets during the nightly full reconcile.',
            'default' => max(0, (int) config('services.client_sync.reconcile_stagger_seconds', 180)),
            'min' => 0,
            'max' => 900,
            'unit' => 'seconds',
        ]);
        $add([
            'key' => 'ops.sync.market_health_max_seconds',
            'group' => self::GROUP_SYNC,
            'label' => 'Market health budget',
            'description' => 'Wall-clock one market-health pass may spend probing markets.',
            'default' => 120,
            'min' => 30,
            'max' => 300,
            'unit' => 'seconds',
        ]);
        $add([
            'key' => 'ops.sync.stale_run_minutes',
            'group' => self::GROUP_SYNC,
            'label' => 'Stalled slice window',
            'description' => 'An active sync run whose heartbeat is older than this counts as a stalled slice.',
            'default' => 15,
            'min' => 2,
            'max' => 120,
            'unit' => 'minutes',
        ]);

        // ---- Queue lanes ------------------------------------------------------
        foreach ([
            ['fast', 'Fast lane job limit', 100, 10, 500],
            ['sync', 'Sync lane job limit', 50, 10, 300],
            ['optimize', 'Optimize lane job limit', 30, 5, 200],
            ['heavy', 'Heavy lane job limit', 10, 1, 100],
        ] as [$lane, $label, $default, $min, $max]) {
            $add([
                'key' => "ops.lanes.{$lane}.max_jobs",
                'group' => self::GROUP_LANES,
                'label' => $label,
                'description' => "Jobs one {$lane}-lane worker processes before it exits and the scheduler starts a fresh one.",
                'default' => $default,
                'min' => $min,
                'max' => $max,
                'unit' => 'jobs',
                'risk' => 'medium',
            ]);
        }
        $add([
            'key' => 'ops.lanes.worker_max_time',
            'group' => self::GROUP_LANES,
            'label' => 'Worker lifetime',
            'description' => 'Seconds a worker runs before exiting. Must stay under 60 so a worker never outlives the cron minute that started it.',
            'default' => 55,
            'min' => 15,
            'max' => 55,
            'unit' => 'seconds',
            'risk' => 'high',
        ]);

        // ---- Limp mode thresholds --------------------------------------------
        $add([
            'key' => 'ops.enforcement.enabled',
            'group' => self::GROUP_THRESHOLDS,
            'label' => 'Enforce load shedding',
            'description' => 'Off by default. Levels are still computed, recorded and alerted — this decides whether crossing one actually pauses work.',
            'type' => self::TYPE_BOOLEAN,
            'default' => false,
            'risk' => 'high',
        ]);
        $add([
            'key' => 'ops.threshold.php_processes.ceiling',
            'group' => self::GROUP_THRESHOLDS,
            'label' => 'PHP process ceiling',
            'description' => 'The account entry-process limit. Every process threshold is read against this.',
            'default' => 40,
            'min' => 5,
            'max' => 500,
            'unit' => 'processes',
            'risk' => 'high',
        ]);
        $add([
            'key' => 'ops.threshold.php_processes.ceiling_verified',
            'group' => self::GROUP_THRESHOLDS,
            'label' => 'Ceiling confirmed with the host',
            'description' => 'Leave off until the real cPanel entry-process limit is known. While off, the board labels the percentage as unverified.',
            'type' => self::TYPE_BOOLEAN,
            'default' => false,
        ]);

        foreach ([
            ['php_processes', 'PHP processes', 26, 32, 'processes', 1, 500],
            ['concurrent_schedule_runs', 'Concurrent scheduler ticks', 2, 3, 'ticks', 1, 50],
            ['scheduler_tick_seconds', 'Scheduler tick duration', 10, 25, 'seconds', 1, 300],
            ['queue_depth', 'Queue depth', 500, 2000, 'jobs', 10, 100000],
            ['oldest_job_seconds', 'Oldest waiting job', 300, 900, 'seconds', 30, 7200],
            ['failed_job_rate', 'Failed jobs per minute', 10, 40, 'jobs/min', 1, 1000],
            ['sync_slice_stalls', 'Stalled sync slices', 1, 3, 'runs', 1, 100],
            ['markets_down', 'Markets down', 5, 15, 'markets', 1, 100],
            ['db_threads_connected', 'MariaDB threads', 60, 100, 'threads', 5, 1000],
        ] as [$signal, $label, $watch, $shed, $unit, $min, $max]) {
            $add([
                'key' => "ops.threshold.{$signal}.watch",
                'group' => self::GROUP_THRESHOLDS,
                'label' => "{$label} — watch",
                'description' => "Crossing this for consecutive samples moves the platform to Cautious.",
                'default' => $watch,
                'min' => $min,
                'max' => $max,
                'unit' => $unit,
            ]);
            $add([
                'key' => "ops.threshold.{$signal}.shed",
                'group' => self::GROUP_THRESHOLDS,
                'label' => "{$label} — shed",
                'description' => "Crossing this for consecutive samples moves the platform to Limp.",
                'default' => $shed,
                'min' => $min,
                'max' => $max,
                'unit' => $unit,
                'risk' => 'medium',
            ]);
        }

        $add([
            'key' => 'ops.threshold.scheduler_tick_seconds.critical',
            'group' => self::GROUP_THRESHOLDS,
            'label' => 'Scheduler tick — critical',
            'description' => 'A tick this long is the outage in progress. Crossing it goes straight to Critical.',
            'default' => 50,
            'min' => 5,
            'max' => 600,
            'unit' => 'seconds',
            'risk' => 'high',
        ]);
        $add([
            'key' => 'ops.hysteresis.escalate_samples',
            'group' => self::GROUP_THRESHOLDS,
            'label' => 'Samples before escalating',
            'description' => 'Consecutive breaching samples required to raise the level. One sample is noise.',
            'default' => 2,
            'min' => 1,
            'max' => 10,
            'unit' => 'samples',
        ]);
        $add([
            'key' => 'ops.hysteresis.recover_samples',
            'group' => self::GROUP_THRESHOLDS,
            'label' => 'Samples before recovering',
            'description' => 'Consecutive clear samples required to drop a level. Deliberately slower than escalation so the system settles instead of flapping.',
            'default' => 5,
            'min' => 1,
            'max' => 30,
            'unit' => 'samples',
        ]);

        // ---- Alert routing ----------------------------------------------------
        $add([
            'key' => 'ops.alerts.banner_enabled',
            'group' => self::GROUP_ALERTS,
            'label' => 'In-app banner',
            'description' => 'Show a banner across the CRM while the platform is degraded.',
            'type' => self::TYPE_BOOLEAN,
            'default' => true,
        ]);
        $add([
            'key' => 'ops.alerts.sms_enabled',
            'group' => self::GROUP_ALERTS,
            'label' => 'SMS to admins',
            'description' => 'Reuses the market-down recipient list, cooldown and dedup.',
            'type' => self::TYPE_BOOLEAN,
            'default' => true,
        ]);
        $add([
            'key' => 'ops.alerts.quiet_hours_enabled',
            'group' => self::GROUP_ALERTS,
            'label' => 'Respect quiet hours',
            'description' => 'Suppress SMS overnight. Never applied at Critical — level 3 always rings.',
            'type' => self::TYPE_BOOLEAN,
            'default' => true,
        ]);
        $add([
            'key' => 'ops.alerts.quiet_hours_start',
            'group' => self::GROUP_ALERTS,
            'label' => 'Quiet hours start',
            'description' => 'Africa/Nairobi.',
            'type' => self::TYPE_TIME,
            'default' => '22:00',
        ]);
        $add([
            'key' => 'ops.alerts.quiet_hours_end',
            'group' => self::GROUP_ALERTS,
            'label' => 'Quiet hours end',
            'description' => 'Africa/Nairobi.',
            'type' => self::TYPE_TIME,
            'default' => '06:00',
        ]);
        $add([
            'key' => 'ops.alerts.cooldown_minutes',
            'group' => self::GROUP_ALERTS,
            'label' => 'Alert cooldown',
            'description' => 'Minimum gap between degradation SMS, so a system oscillating around a threshold cannot page repeatedly.',
            'default' => 30,
            'min' => 5,
            'max' => 720,
            'unit' => 'minutes',
        ]);

        return $definitions;
    }
}
