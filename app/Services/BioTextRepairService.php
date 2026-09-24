<?php

namespace App\Services;

use App\Models\BioTextFinding;
use App\Models\BioTextScan;
use App\Models\Client;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Support\BioTextIntegrity;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Finds profile bios with broken text in one market and repairs them in
 * WordPress. Each phase is a dry run, a backup, an apply and a verify:
 *
 * - scan: read every linked bio, record the broken ones with a copy of each;
 * - repair: re-read, back up what is there now, write the safe fixes, then
 *   read the bio back and only call it repaired if WordPress shows clean text;
 * - restore: put the backed-up bio back, unless someone has edited it since.
 *
 * RunBioTextScanJob drives the phases one slice at a time.
 */
class BioTextRepairService
{
    public const SCAN_SLICE = 60;

    public const WRITE_SLICE = 20;

    public const CONCURRENCY = 8;

    /** Most findings one repair or restore request may name. */
    public const MAX_SELECTION = 1000;

    /** Issues a person has to rewrite; the repair never guesses at these. */
    public const MANUAL_KINDS = [BioTextIntegrity::LOST_CHARACTERS, BioTextIntegrity::AI_TEXT];

    public function start(Platform $platform, ?int $userId): BioTextScan
    {
        return BioTextScan::create([
            'platform_id' => (int) $platform->id,
            'requested_by' => $userId,
            'status' => BioTextScan::STATUS_QUEUED,
            'total_profiles' => $this->linkedClients((int) $platform->id)->count(),
        ]);
    }

    /** Read one slice of the market's bios; true while more remain. */
    public function scanSlice(BioTextScan $scan): bool
    {
        if (! $scan->started_at) {
            $scan->forceFill(['status' => BioTextScan::STATUS_SCANNING, 'started_at' => now()])->save();
        }

        $clients = $this->linkedClients((int) $scan->platform_id)
            ->where('id', '>', (int) $scan->cursor_client_id)
            ->orderBy('id')
            ->limit(self::SCAN_SLICE)
            ->get(['id', 'wp_post_id', 'name']);

        if ($clients->isEmpty()) {
            $this->finishScan($scan);

            return false;
        }

        $bios = $this->wp($scan)->getClientBiosPool($this->postIds($clients->pluck('wp_post_id')->all()), self::CONCURRENCY);

        $unreadable = 0;
        foreach ($clients as $client) {
            $bio = $bios[(int) $client->wp_post_id] ?? null;
            if ($bio === null) {
                $unreadable++;

                continue;
            }

            $report = BioTextIntegrity::inspect($bio, BioTextIntegrity::FORMAT_HTML, BioTextIntegrity::SCAN_KINDS);
            if ($report['clean']) {
                continue;
            }

            BioTextFinding::query()->updateOrCreate(
                ['scan_id' => (int) $scan->id, 'wp_post_id' => (int) $client->wp_post_id],
                [
                    'platform_id' => (int) $scan->platform_id,
                    'client_id' => (int) $client->id,
                    'client_name' => mb_substr((string) $client->name, 0, 255),
                    'status' => BioTextFinding::STATUS_FOUND,
                    'original_html' => $bio,
                    'original_hash' => sha1($bio),
                ] + $this->reportColumns($bio, $report)
            );
        }

        // A slice where nothing answered means the site is down, not that
        // every bio is clean: stop rather than report a false all-clear.
        if ($unreadable === $clients->count() && $unreadable >= 5) {
            throw new RuntimeException('The market site stopped answering, so the check stopped. Nothing was changed. Try again once the site is back.');
        }

        $scan->forceFill([
            'cursor_client_id' => (int) $clients->last()->id,
            'profiles_scanned' => (int) $scan->profiles_scanned + $clients->count(),
            'profiles_unreadable' => (int) $scan->profiles_unreadable + $unreadable,
        ])->save();

        return true;
    }

    /**
     * Queue fixable findings for repair (all of them, or the chosen ones).
     *
     * @param  list<int>|null  $findingIds
     */
    public function queueRepair(BioTextScan $scan, ?array $findingIds, ?int $userId): int
    {
        $query = $scan->findings()
            ->where('fixable', true)
            ->whereIn('status', [...BioTextFinding::REPAIRABLE_STATUSES, BioTextFinding::STATUS_QUEUED]);
        if ($findingIds !== null) {
            $query->whereIn('id', $findingIds);
        }

        // Count matches before the update. MySQL reports zero affected rows when
        // every matching finding is already queued, which made a failed run
        // impossible to resume even though work was still waiting.
        $count = $query->count();
        if ($count > 0) {
            $query->update(['status' => BioTextFinding::STATUS_QUEUED, 'error' => null]);
        }
        if ($count > 0) {
            $scan->forceFill([
                'status' => BioTextScan::STATUS_REPAIRING,
                'repair_requested_by' => $userId,
                'repair_target' => $count,
                'repaired' => 0,
                'repair_unchanged' => 0,
                'repair_failed' => 0,
                'repair_started_at' => now(),
                'finished_at' => null,
                'notes' => null,
            ])->save();
        }

        return $count;
    }

    /** Repair one slice of queued findings; true while more remain. */
    public function repairSlice(BioTextScan $scan): bool
    {
        $findings = $scan->findings()
            ->where('status', BioTextFinding::STATUS_QUEUED)
            ->orderBy('id')
            ->limit(self::WRITE_SLICE)
            ->get();

        if ($findings->isEmpty()) {
            $scan->forceFill([
                'status' => BioTextScan::STATUS_REPAIRED,
                'finished_at' => now(),
                'notes' => $scan->repair_failed > 0
                    ? sprintf('%d %s could not be repaired. Each one says why in the list.', $scan->repair_failed, $scan->repair_failed === 1 ? 'bio' : 'bios')
                    : null,
            ])->save();

            return false;
        }

        $wp = $this->wp($scan);
        $current = $wp->getClientBiosPool($this->postIds($findings->pluck('wp_post_id')->all()), self::CONCURRENCY);
        if ($findings->count() >= 5 && array_filter($current, static fn ($bio) => $bio !== null) === []) {
            throw new RuntimeException('The market site stopped answering, so the repair paused. Bios already repaired stay repaired; start the repair again to finish the rest.');
        }

        $written = [];
        $counts = ['repaired' => 0, 'repair_unchanged' => 0, 'repair_failed' => 0];

        foreach ($findings as $finding) {
            $bio = $current[(int) $finding->wp_post_id] ?? null;
            if ($bio === null) {
                $this->markFailed($finding, 'WordPress did not return this bio, so nothing was changed.');
                $counts['repair_failed']++;

                continue;
            }

            if (! BioTextIntegrity::needsFix($bio)) {
                $finding->forceFill(['status' => BioTextFinding::STATUS_UNCHANGED, 'error' => null])->save();
                $counts['repair_unchanged']++;

                continue;
            }

            if (sha1($bio) !== $finding->original_hash) {
                // Edited since the scan: the backup is what is there now.
                $finding->forceFill([
                    'original_html' => $bio,
                    'original_hash' => sha1($bio),
                ] + $this->reportColumns($bio))->save();
            }

            $fixed = BioTextIntegrity::fix($bio, BioTextIntegrity::FORMAT_HTML, BioTextIntegrity::SAFE_FIXES);

            try {
                $wp->updateClientProfile((int) $finding->wp_post_id, ['content' => $fixed]);
            } catch (\Throwable $exception) {
                $this->markFailed($finding, 'WordPress did not accept the update: '.$exception->getMessage());
                $counts['repair_failed']++;

                continue;
            }

            $written[(int) $finding->wp_post_id] = [$finding, $fixed];
        }

        if ($written !== []) {
            // Verify against what WordPress now serves, not what was sent.
            $after = $wp->getClientBiosPool(array_keys($written), self::CONCURRENCY);

            foreach ($written as $postId => [$finding, $fixed]) {
                $served = $after[$postId] ?? null;
                $verified = $served !== null && ! BioTextIntegrity::needsFix($served);

                $finding->forceFill([
                    'status' => $verified ? BioTextFinding::STATUS_REPAIRED : BioTextFinding::STATUS_FAILED,
                    'repaired_html' => $served ?? $fixed,
                    'repaired_at' => now(),
                    'restored_at' => null,
                    'error' => match (true) {
                        $served === null => 'Saved, but WordPress did not return the bio to confirm the repair. It can be restored from the backup.',
                        ! $verified => 'Saved, but WordPress still shows broken text. It can be restored from the backup.',
                        default => null,
                    },
                ])->save();

                if (! $verified) {
                    $counts['repair_failed']++;

                    continue;
                }

                $counts['repaired']++;
                $this->repairScrubCopy($finding);
                $this->recordTimeline($finding, 'profile_bio_text_repaired', (int) $scan->repair_requested_by);
            }
        }

        $scan->forceFill([
            'repaired' => (int) $scan->repaired + $counts['repaired'],
            'repair_unchanged' => (int) $scan->repair_unchanged + $counts['repair_unchanged'],
            'repair_failed' => (int) $scan->repair_failed + $counts['repair_failed'],
        ])->save();

        return true;
    }

    /**
     * Queue repaired findings to have their backed-up bio put back.
     *
     * @param  list<int>|null  $findingIds
     */
    public function queueRestore(BioTextScan $scan, ?array $findingIds, ?int $userId): int
    {
        $query = $this->restorable($scan);
        if ($findingIds !== null) {
            $query->whereIn('id', $findingIds);
        }

        $count = $query->update(['status' => BioTextFinding::STATUS_RESTORE_QUEUED]);
        if ($count > 0) {
            $scan->forceFill([
                'status' => BioTextScan::STATUS_RESTORING,
                'restore_requested_by' => $userId,
                'restore_target' => $count,
                'restored' => 0,
                'restore_failed' => 0,
                'finished_at' => null,
                'notes' => null,
            ])->save();
        }

        return $count;
    }

    /** Put back one slice of backed-up bios; true while more remain. */
    public function restoreSlice(BioTextScan $scan): bool
    {
        $findings = $scan->findings()
            ->where('status', BioTextFinding::STATUS_RESTORE_QUEUED)
            ->orderBy('id')
            ->limit(self::WRITE_SLICE)
            ->get();

        if ($findings->isEmpty()) {
            $failed = (int) $scan->restore_failed;
            $scan->forceFill([
                'status' => $failed > 0 ? BioTextScan::STATUS_FAILED : BioTextScan::STATUS_RESTORED,
                'finished_at' => now(),
                'notes' => $failed > 0
                    ? sprintf('%d %s not put back. Each one says why in the list.', $failed, $failed === 1 ? 'bio was' : 'bios were')
                    : null,
            ])->save();

            return false;
        }

        $wp = $this->wp($scan);
        $current = $wp->getClientBiosPool($this->postIds($findings->pluck('wp_post_id')->all()), self::CONCURRENCY);
        $restored = 0;
        $failed = 0;

        foreach ($findings as $finding) {
            $bio = $current[(int) $finding->wp_post_id] ?? null;
            $keep = fn (string $error) => $finding->forceFill(['status' => BioTextFinding::STATUS_REPAIRED, 'error' => $error])->save();

            if ($bio === null) {
                $keep('WordPress did not return this bio, so it was not put back. Try again.');
                $failed++;

                continue;
            }

            if ($bio !== $finding->original_html) {
                if ($finding->repaired_html !== null && $bio !== $finding->repaired_html) {
                    $keep('Edited after the repair, so it was left alone to keep the newer text.');
                    $failed++;

                    continue;
                }

                try {
                    // Exactly as it was: the encoding backstop must not re-repair it.
                    $wp->updateClientProfile((int) $finding->wp_post_id, ['content' => $finding->original_html], repairBioText: false);
                } catch (\Throwable $exception) {
                    $keep('WordPress did not accept the restore: '.$exception->getMessage());
                    $failed++;

                    continue;
                }
            }

            $this->restoreScrubCopy($finding);
            $finding->forceFill([
                'status' => BioTextFinding::STATUS_RESTORED,
                'restored_at' => now(),
                'error' => null,
            ])->save();
            $this->recordTimeline($finding, 'profile_bio_text_restored', (int) $scan->restore_requested_by);
            $restored++;
        }

        $scan->forceFill([
            'restored' => (int) $scan->restored + $restored,
            'restore_failed' => (int) $scan->restore_failed + $failed,
        ])->save();

        return true;
    }

    public function restorable(BioTextScan $scan): Builder
    {
        return BioTextFinding::query()
            ->where('scan_id', (int) $scan->id)
            ->whereNotNull('repaired_at')
            ->whereIn('status', [
                BioTextFinding::STATUS_REPAIRED,
                BioTextFinding::STATUS_FAILED,
                BioTextFinding::STATUS_RESTORE_QUEUED,
            ]);
    }

    /** Columns derived from a bio's report: issues, kinds, severity, fixable. */
    private function reportColumns(string $bio, ?array $report = null): array
    {
        $report ??= BioTextIntegrity::inspect($bio, BioTextIntegrity::FORMAT_HTML, BioTextIntegrity::SCAN_KINDS);
        $kinds = array_column($report['issues'], 'kind');

        return [
            'issues' => $report['issues'],
            'kinds' => $kinds === [] ? '' : ','.implode(',', $kinds).',',
            'severity' => $report['errors'] > 0 ? 'error' : 'warning',
            'fixable' => BioTextIntegrity::needsFix($bio),
        ];
    }

    private function finishScan(BioTextScan $scan): void
    {
        $issueCounts = [];
        $affected = 0;
        $fixable = 0;

        $scan->findings()->select(['id', 'kinds', 'fixable'])->chunkById(500, function ($findings) use (&$issueCounts, &$affected, &$fixable): void {
            foreach ($findings as $finding) {
                $affected++;
                $fixable += $finding->fixable ? 1 : 0;
                foreach (array_filter(explode(',', (string) $finding->kinds)) as $kind) {
                    $issueCounts[$kind] = ($issueCounts[$kind] ?? 0) + 1;
                }
            }
        });

        $unreadable = (int) $scan->profiles_unreadable;
        $scan->forceFill([
            'status' => BioTextScan::STATUS_SCANNED,
            'scanned_at' => now(),
            'finished_at' => now(),
            'total_profiles' => max((int) $scan->total_profiles, (int) $scan->profiles_scanned),
            'profiles_affected' => $affected,
            'profiles_fixable' => $fixable,
            'issue_counts' => $issueCounts,
            'notes' => $unreadable > 0
                ? sprintf('%d %s could not be read from WordPress and %s skipped. Check again later to include %s.', $unreadable, $unreadable === 1 ? 'profile' : 'profiles', $unreadable === 1 ? 'was' : 'were', $unreadable === 1 ? 'it' : 'them')
                : null,
        ])->save();
    }

    /** The CRM keeps the original of a contact-scrubbed bio; renewal restores it, so it must be clean too. */
    private function repairScrubCopy(BioTextFinding $finding): void
    {
        $client = $finding->client_id ? Client::query()->find($finding->client_id) : null;
        $copy = (string) ($client?->bio_original_html ?? '');
        if ($copy === '' || ! BioTextIntegrity::needsFix($copy)) {
            return;
        }

        Client::withoutRetentionRefresh(function () use ($client, $copy): void {
            $client->forceFill(['bio_original_html' => BioTextIntegrity::fix($copy, BioTextIntegrity::FORMAT_HTML, BioTextIntegrity::SAFE_FIXES)])->save();
        });
        $finding->forceFill(['scrub_original_before' => $copy])->save();
    }

    private function restoreScrubCopy(BioTextFinding $finding): void
    {
        $client = $finding->client_id ? Client::query()->find($finding->client_id) : null;
        if (! $client || $finding->scrub_original_before === null || $client->bio_original_html === null) {
            return;
        }

        Client::withoutRetentionRefresh(function () use ($client, $finding): void {
            $client->forceFill(['bio_original_html' => $finding->scrub_original_before])->save();
        });
        $finding->forceFill(['scrub_original_before' => null])->save();
    }

    private function recordTimeline(BioTextFinding $finding, string $event, int $actorId): void
    {
        if (! $finding->client_id) {
            return;
        }

        TimelineEvent::create([
            'platform_id' => (int) $finding->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $finding->client_id,
            'event_type' => $event,
            'actor_id' => $actorId ?: null,
            'content' => [
                'bio_text_scan_id' => (int) $finding->scan_id,
                'kinds' => array_values(array_filter(explode(',', (string) $finding->kinds))),
            ],
            'created_at' => now(),
        ]);
    }

    private function markFailed(BioTextFinding $finding, string $error): void
    {
        $finding->forceFill(['status' => BioTextFinding::STATUS_FAILED, 'error' => mb_substr($error, 0, 500)])->save();
    }

    private function linkedClients(int $platformId): Builder
    {
        return Client::query()->where('platform_id', $platformId)->where('wp_post_id', '>', 0);
    }

    /** @return list<int> */
    private function postIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function wp(BioTextScan $scan): WpSyncService
    {
        // Operator-initiated: an admin started this for a market they are watching.
        return (new WpSyncService(Platform::query()->findOrFail((int) $scan->platform_id)))->bypassHealthGate();
    }
}
