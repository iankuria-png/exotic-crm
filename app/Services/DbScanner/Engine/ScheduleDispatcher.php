<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanConnection;
use App\Models\DbScanDailyBudget;
use App\Models\DbScanMarketRun;
use App\Models\DbScanOccurrence;
use App\Models\DbScanSchedule;
use App\Models\DbScanSweep;
use App\Models\Platform;
use App\Services\DbScanner\ScannerSettings;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The minute dispatcher (CRM-only, bounded):
 *
 * - publishes outbox intents a crash left behind;
 * - auto-resumes load/health/window pauses whose condition cleared;
 * - turns schedules into deduplicated UTC occurrences per market, catching
 *   up only the latest occurrence within 24 hours and deferring (never
 *   discarding) conflicts;
 * - continues unfinished sweeps fairly, respecting the per-market daily
 *   active-work quota and a minimum gap between runs.
 */
class ScheduleDispatcher
{
    public const HEARTBEAT_KEY = 'db_scanner.dispatcher_heartbeat';

    public function __construct(
        private readonly ScannerSettings $settings,
        private readonly ScannerGate $gate,
        private readonly AdmissionService $admission,
        private readonly Outbox $outbox,
        private readonly PassController $passes,
        private readonly PassStatus $passStatus,
        private readonly RunLogger $log,
    ) {}

    /**
     * @return array<string, int>
     */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        Cache::put(self::HEARTBEAT_KEY, $now->toIso8601String(), now()->addDay());

        $summary = ['published' => $this->outbox->publishPending(100), 'resumed' => 0, 'occurrences' => 0, 'dispatched' => 0, 'continued' => 0, 'expired' => 0];

        $summary['expired'] = DbScanSweep::query()->where('status', 'running')->where('expires_at', '<', $now)->update([
            'status' => 'expired', 'stop_reason' => 'sweep_deadline', 'finished_at' => $now, 'updated_at' => $now,
        ]);

        if (! $this->settings->scanningAllowed()) {
            return $summary;
        }

        $summary['resumed'] = $this->resumeAutoPaused($now);
        [$summary['occurrences'], $summary['dispatched']] = $this->schedules($now);
        $summary['continued'] = $this->continuations($now);

        return $summary;
    }

    private function resumeAutoPaused(CarbonImmutable $now): int
    {
        $resumed = 0;
        $runs = DbScanMarketRun::query()
            ->where('status', 'paused')
            ->whereIn('pause_reason', ['load', 'health', 'window'])
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
            ->limit(50)
            ->get();

        foreach ($runs as $run) {
            $platform = Platform::query()->find($run->platform_id);
            $connection = DbScanConnection::query()->where('platform_id', $run->platform_id)->first();
            $blocked = $platform ? $this->gate->check($platform, $connection, 'scan') : 'health';
            if ($blocked === null && $run->pause_reason === 'window' && $platform) {
                $schedule = $run->pass?->schedule_id ? DbScanSchedule::query()->find($run->pass->schedule_id) : null;
                if ($schedule && ! ScheduleWindow::isOpen((array) $schedule->window, $platform->timezone ?: 'UTC', $now)) {
                    $blocked = 'window';
                }
            }

            DB::transaction(function () use ($run, $blocked, $now, &$resumed) {
                $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'paused' || ! in_array($locked->pause_reason, ['load', 'health', 'window'], true)) {
                    return;
                }
                if ($blocked !== null) {
                    $locked->forceFill(['next_attempt_at' => $now->addMinute()])->save();

                    return;
                }
                $generation = (int) $locked->generation + 1;
                $locked->forceFill(['status' => 'queued', 'pause_reason' => null, 'generation' => $generation, 'next_attempt_at' => null])->save();
                $this->outbox->enqueue((int) $locked->id, $generation);
                $this->log->log($locked, 'info', 'Resumed automatically: the '.$run->pause_reason.' condition cleared.');
                $resumed++;
            });
            $this->passStatus->refresh((int) $run->pass_id);
        }

        if ($resumed > 0) {
            $this->outbox->publishPending();
        }

        return $resumed;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function schedules(CarbonImmutable $now): array
    {
        $created = 0;
        $dispatched = 0;

        foreach (DbScanSchedule::query()->where('enabled', true)->get() as $schedule) {
            try {
                $cron = new CronExpression($schedule->cron);
            } catch (\Throwable) {
                continue;
            }

            $platformIds = $this->scope($schedule);
            $nextDue = null;

            foreach (Platform::query()->whereIn('id', $platformIds)->get() as $platform) {
                $tz = $platform->timezone ?: config('app.timezone', 'UTC');
                try {
                    $localNow = $now->setTimezone($tz);
                    $previous = CarbonImmutable::instance($cron->getPreviousRunDate($localNow->toDateTimeImmutable(), 0, true, $tz));
                    $upcoming = CarbonImmutable::instance($cron->getNextRunDate($localNow->toDateTimeImmutable(), 0, false, $tz));
                } catch (\Throwable) {
                    continue;
                }
                $nextDue = $nextDue === null || $upcoming->lt($nextDue) ? $upcoming : $nextDue;

                if ($previous->lt($now->subDay())) {
                    continue;
                }

                $inserted = DB::table('db_scan_occurrences')->insertOrIgnore([
                    'schedule_id' => $schedule->id,
                    'schedule_revision' => $schedule->revision,
                    'platform_id' => $platform->id,
                    'due_at_utc' => $previous->utc()->format('Y-m-d H:i:s'),
                    'state' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created += $inserted;
            }

            if ($nextDue) {
                $schedule->forceFill(['next_due_at' => $nextDue->utc()])->save();
            }

            $dispatched += $this->admitOccurrences($schedule, $now);
        }

        return [$created, $dispatched];
    }

    private function admitOccurrences(DbScanSchedule $schedule, CarbonImmutable $now): int
    {
        $pending = DbScanOccurrence::query()
            ->where('schedule_id', $schedule->id)
            ->where('state', 'pending')
            ->orderBy('due_at_utc')
            ->get()
            ->groupBy('platform_id');

        $admit = [];
        $occurrenceIds = [];
        foreach ($pending as $platformId => $occurrences) {
            $latest = $occurrences->last();
            foreach ($occurrences as $occurrence) {
                if ($occurrence->id === $latest->id) {
                    continue;
                }
                $occurrence->forceFill(['state' => 'coalesced', 'skip_reason' => 'a later occurrence superseded it'])->save();
            }
            if ((int) $latest->schedule_revision !== (int) $schedule->revision) {
                $latest->forceFill(['state' => 'cancelled', 'skip_reason' => 'schedule revised'])->save();

                continue;
            }
            if ($latest->due_at_utc->lt($now->subDay())) {
                $latest->forceFill(['state' => 'skipped', 'skip_reason' => 'not admitted within 24 hours'])->save();

                continue;
            }

            $platform = Platform::query()->find($platformId);
            $connection = DbScanConnection::query()->where('platform_id', $platformId)->first();
            if (! $platform || $this->gate->check($platform, $connection, 'scan') !== null) {
                continue; // deferred, retried next tick
            }
            if (! ScheduleWindow::isOpen((array) $schedule->window, $platform->timezone ?: 'UTC', $now)) {
                continue;
            }
            if (DB::transaction(fn () => $this->admission->marketBusy((int) $platformId))) {
                $latest->forceFill(['skip_reason' => 'deferred: a prior scan is still active'])->save();

                continue;
            }
            $openSweep = DbScanSweep::query()->where('platform_id', $platformId)->where('profile', $schedule->profile)->where('status', 'running')->exists();
            if ($openSweep) {
                $latest->forceFill(['state' => 'coalesced', 'skip_reason' => 'an unfinished sweep for this profile is still being continued'])->save();

                continue;
            }

            $admit[] = (int) $platformId;
            $occurrenceIds[] = $latest->id;
        }

        if ($admit === []) {
            return 0;
        }

        try {
            $pass = $this->passes->start($admit, $schedule->profile, 'schedule', null, null, false, null, (int) $schedule->id);
        } catch (MarketBusyException) {
            return 0;
        }

        DbScanOccurrence::query()->whereIn('id', $occurrenceIds)->update(['state' => 'dispatched', 'claimed_at' => $now, 'pass_id' => $pass->id, 'skip_reason' => null, 'updated_at' => $now]);
        $schedule->forceFill(['last_dispatched_at' => $now])->save();

        return count($admit);
    }

    private function continuations(CarbonImmutable $now): int
    {
        $continued = 0;
        $gap = (int) config('db_scanner.envelope.continuation_gap_seconds', 60);
        $quota = $this->settings->dailyMarketSeconds();
        $today = $now->utc()->toDateString();

        $sweeps = DbScanSweep::query()
            ->where('status', 'running')
            ->where('continuation_paused', false)
            ->where('expires_at', '>', $now)
            ->where(fn ($q) => $q->whereNull('last_served_at')->orWhere('last_served_at', '<=', $now->subSeconds($gap)))
            ->orderBy('last_served_at')
            ->limit(20)
            ->get();

        foreach ($sweeps as $sweep) {
            $hasLiveRun = DbScanMarketRun::query()->where('sweep_id', $sweep->id)->whereNotIn('status', DbScanMarketRun::TERMINAL)->exists();
            if ($hasLiveRun) {
                continue;
            }
            $used = (float) DbScanDailyBudget::query()->where('platform_id', $sweep->platform_id)->where('day', $today)->value('active_seconds');
            if ($used >= $quota) {
                continue; // quota debt stays visible on the sweep
            }
            $platform = Platform::query()->find($sweep->platform_id);
            $connection = DbScanConnection::query()->where('platform_id', $sweep->platform_id)->first();
            if (! $platform || $this->gate->check($platform, $connection, 'scan') !== null) {
                continue;
            }
            if ($this->passes->continueSweep($sweep)) {
                $continued++;
            }
        }

        return $continued;
    }

    /**
     * @return array<int, int>
     */
    private function scope(DbScanSchedule $schedule): array
    {
        $query = DbScanConnection::query()->where('enabled', true);
        $ids = $query->get()->filter(fn (DbScanConnection $c) => $c->preflightValid())->pluck('platform_id')->map(fn ($id) => (int) $id)->all();
        $scope = (array) $schedule->market_scope;
        if (($scope['mode'] ?? 'enabled_connections') === 'platforms') {
            $ids = array_values(array_intersect($ids, array_map('intval', (array) ($scope['platform_ids'] ?? []))));
        }

        return $ids;
    }
}
