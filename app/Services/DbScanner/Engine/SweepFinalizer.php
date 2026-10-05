<?php

namespace App\Services\DbScanner\Engine;

use App\Models\DbScanFinding;
use App\Models\DbScanMarketRun;
use App\Models\DbScanSnapshot;
use App\Models\DbScanSweep;
use App\Services\DbScanner\Malware\HostContext;
use App\Services\DbScanner\Reader\MarketDbReader;
use App\Services\DbScanner\Rules\Hit;
use App\Services\DbScanner\Rules\RuleSet;
use App\Services\DbScanner\Surfaces\SchemaInfo;
use App\Services\DbScanner\Surfaces\SurfaceRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Runs once a sweep's traversal reaches its end:
 *
 * 1. accumulator rules (outbound domains, shorteners, comment links),
 * 2. reinfection correlation across independent signals,
 * 3. resolution — only for findings whose rule is active, whose subject
 *    surface was fully examined in this sweep under the same rule
 *    semantics, and which pass a bounded point recheck. Drift alerts are
 *    change notices and never auto-resolve.
 *
 * Returns the run's terminal status: completed or completed_with_gaps.
 */
class SweepFinalizer
{
    private const RECHECK_LIMIT = 300;

    private const NON_RESOLVING_KINDS = ['drift'];

    public function __construct(
        private readonly SurfaceRegistry $registry,
        private readonly FindingRecorder $recorder,
        private readonly SurfaceScanner $scanner,
        private readonly RunLogger $log,
    ) {}

    public function finalize(
        DbScanMarketRun $run,
        string $token,
        array &$state,
        RuleSet $rules,
        MarketDbReader $reader,
        SchemaInfo $schema,
        HostContext $hosts,
        ?DbScanSweep $sweep,
        callable $persist,
    ): string {
        $status = $this->status($state);

        if ($run->mode === 'test' || ! $sweep) {
            $persist();

            return $status;
        }

        $hits = array_merge($this->accumulatorHits($run, $state, $rules), $this->correlationHits($run, $rules, $sweep), (new \App\Services\DbScanner\FleetCampaignCorrelator)->hits((int) $run->platform_id, $rules));

        if ($hits !== []) {
            DB::transaction(function () use ($run, $token, $hits, $rules) {
                $locked = DbScanMarketRun::query()->whereKey($run->id)->lockForUpdate()->first();
                if (! $locked || $locked->owner_token !== $token) {
                    throw new LeaseLostException;
                }
                $counters = (array) ($locked->metrics['counters'] ?? []);
                $stats = $this->recorder->record($hits, $locked, $rules, $counters);
                $metrics = (array) ($locked->metrics ?? []);
                $metrics['counters'] = $counters;
                $metrics['findings_new'] = (int) ($metrics['findings_new'] ?? 0) + $stats['new'] + $stats['reopened'];
                $locked->metrics = $metrics;
                $locked->save();
            });
        }

        $resolved = $this->resolve($run, $state, $rules, $reader, $schema, $hosts, $sweep);

        $state['finalized'] = true;
        $state['resolved'] = $resolved;
        $persist();

        DbScanMarketRun::query()->whereKey($run->id)->update(['resolution_completed_at' => now()]);
        $this->log->log($run, 'info', sprintf('Sweep finished: %d findings resolved by verification, status %s.', $resolved, str_replace('_', ' ', $status)));

        return $status;
    }

    public function status(array $state): string
    {
        foreach ((array) ($state['surfaces'] ?? []) as $s) {
            if (! in_array($s['status'] ?? null, ['complete', 'not_applicable'], true)
                && ! (($s['status'] ?? null) === 'excluded' && ($s['reason'] ?? null) === 'no_active_rules')) {
                return 'completed_with_gaps';
            }
        }
        foreach ((array) ($state['inventory']['surfaces'] ?? []) as $s) {
            if (! in_array($s['status'] ?? null, ['complete', 'not_applicable'], true)) {
                return 'completed_with_gaps';
            }
        }
        if (! empty($state['rule_flags']) || ! empty($state['unsupported'])) {
            return 'completed_with_gaps';
        }

        return 'completed';
    }

    /**
     * @return array<int, Hit>
     */
    private function accumulatorHits(DbScanMarketRun $run, array &$state, RuleSet $rules): array
    {
        $acc = (array) ($state['acc'] ?? []);
        $hits = [];

        if ($rules->active('content.shortener_links') && $this->surfaceComplete($state, 'posts.content')) {
            foreach ((array) ($acc['content.shortener_links'] ?? []) as $host => $data) {
                $hits[] = $this->sweepHit('content.shortener_links', 'Links through a URL shortener (destination hidden)', 'shortener:'.$host, [
                    'host' => $host, 'occurrences' => $data['count'], 'sample_rows' => $data['samples'],
                ], 'posts.content');
            }
        }

        if ($rules->active('content.comment_links') && $this->surfaceComplete($state, 'comments.approved')) {
            $data = $acc['content.comment_links']['approved_comments_with_links'] ?? null;
            if ($data) {
                $hits[] = $this->sweepHit('content.comment_links', 'Approved comments containing links', 'approved_comments_with_links', [
                    'comments' => $data['count'], 'sample_comment_ids' => $data['samples'],
                ], 'comments.approved');
            }
        }

        if ($rules->active('hygiene.autoloaded_secret_options') && $this->surfaceComplete($state, 'options.values')) {
            $min = (int) $rules->threshold('hygiene.autoloaded_secret_options', 'min_options', 100);
            foreach ((array) ($acc['hygiene.autoloaded_secret_options'] ?? []) as $pattern => $data) {
                if ((int) $data['count'] < $min) {
                    continue;
                }
                $hits[] = $this->sweepHit('hygiene.autoloaded_secret_options', 'Many secret-named options are autoloaded on every request', 'autoloaded_secrets:'.$pattern, [
                    'name_pattern' => $pattern, 'options' => (int) $data['count'], 'bytes' => (int) ($data['bytes'] ?? 0),
                    'note' => 'Values were never read; counted by name and size only.',
                ], 'options.values');
            }
        }

        $snapshotId = $this->sweepSnapshotId($run);
        if ($rules->active('content.outbound_domains') && $snapshotId) {
            $complete = $this->surfaceComplete($state, 'posts.content') && $this->surfaceComplete($state, 'options.values');
            $domains = array_keys((array) ($acc['content.outbound_domains'] ?? []));
            sort($domains);

            $previous = DbScanSnapshot::query()
                ->where('platform_id', $run->platform_id)
                ->where('id', '<', $snapshotId)
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->first(fn ($s) => ($s->components['outbound_domains']['complete'] ?? false) === true);

            if ($complete && $previous) {
                $before = (array) ($previous->components['outbound_domains']['data'] ?? []);
                foreach (array_slice(array_values(array_diff($domains, $before)), 0, 50) as $host) {
                    $data = $acc['content.outbound_domains'][$host];
                    $hits[] = $this->sweepHit('content.outbound_domains', 'New outbound domain since the previous complete sweep', 'outbound:'.$host, [
                        'host' => $host, 'links' => $data['count'], 'sample_rows' => $data['samples'],
                    ], 'posts.content');
                }
            }

            $snapshot = DbScanSnapshot::query()->find($snapshotId);
            if ($snapshot) {
                $components = (array) $snapshot->components;
                $components['outbound_domains'] = ['complete' => $complete, 'data' => array_slice($domains, 0, 2000)];
                $snapshot->forceFill(['components' => $components])->save();
            }
        }

        return $hits;
    }

    /**
     * Two or more independent persistence/malware signals in one sweep.
     *
     * @return array<int, Hit>
     */
    private function correlationHits(DbScanMarketRun $run, RuleSet $rules, DbScanSweep $sweep): array
    {
        if (! $rules->active('malware.reinfection_cluster')) {
            return [];
        }

        $findings = DbScanFinding::query()
            ->where('platform_id', $run->platform_id)
            ->whereIn('status', DbScanFinding::UNRESOLVED_STATUSES)
            ->where('last_seen_at', '>=', $sweep->created_at)
            ->where('rule_key', '!=', 'malware.reinfection_cluster')
            ->get(['id', 'rule_key', 'confidence', 'behavior', 'subject', 'category']);

        $groups = [];
        $surfaces = [];
        $ids = [];
        foreach ($findings as $f) {
            $group = match (true) {
                $f->category === 'malware' && in_array($f->confidence, ['strong', 'confirmed'], true) && $f->behavior !== 'gtm_unbaselined' => 'malware_behaviour',
                in_array($f->rule_key, ['access.admin_count_drift', 'access.hidden_admin_capabilities'], true) && $f->confidence === 'strong' => 'privileged_account',
                $f->rule_key === 'persistence.cron_unknown_hooks' && $f->confidence === 'strong' => 'cron_payload',
                $f->rule_key === 'persistence.code_snippet_stores' => 'stored_code',
                in_array($f->rule_key, ['persistence.mysql_triggers', 'persistence.mysql_events_routines'], true) && $f->confidence === 'strong' => 'schema_object',
                default => null,
            };
            if ($group) {
                $groups[$group] = true;
                $surfaces[(string) ($f->subject['surface'] ?? '?')] = true;
                $ids[] = $f->id;
            }
        }

        if (count($groups) < 2 || count($surfaces) < 2) {
            return [];
        }

        return [new Hit(
            'malware.reinfection_cluster',
            'Independent persistence and malware signals in one sweep',
            ['surface' => 'sweep', 'table' => 'market', 'item' => 'reinfection_cluster', 'object_type' => 'market'],
            ['details' => ['signal_groups' => array_keys($groups), 'linked_finding_ids' => array_slice($ids, 0, 50), 'surfaces' => array_keys($surfaces)], 'signals' => array_keys($groups), 'excerpts' => []],
            'strong',
            'reinfection',
        )];
    }

    private function resolve(DbScanMarketRun $run, array $state, RuleSet $rules, MarketDbReader $reader, SchemaInfo $schema, HostContext $hosts, DbScanSweep $sweep): int
    {
        $resolved = 0;
        $rechecks = 0;
        $skipped = 0;

        $candidates = DbScanFinding::query()
            ->where('platform_id', $run->platform_id)
            ->whereIn('status', DbScanFinding::UNRESOLVED_STATUSES)
            ->where('last_seen_at', '<', $sweep->created_at)
            ->orderBy('id')
            ->limit(5000)
            ->get();

        foreach ($candidates as $finding) {
            $rule = $rules->rule($finding->rule_key);
            if (! $rule || ! $rules->active($finding->rule_key) || in_array($rule['kind'] ?? '', self::NON_RESOLVING_KINDS, true)) {
                continue;
            }
            if ($finding->rule_key === 'malware.reinfection_cluster') {
                // The cluster clears when its linked signals are gone.
                $this->recorder->resolve($finding, $run, 'No longer two independent signals in a complete sweep.');
                $resolved++;

                continue;
            }

            $surfaceKey = (string) ($finding->subject['surface'] ?? '');
            $flags = (array) ($state['rule_flags'][$finding->rule_key] ?? []);
            if (($flags['match_cap'] ?? 0) > 0 || isset($flags['matcher_error:'.$surfaceKey])) {
                continue;
            }

            if ($surfaceKey === 'sweep' || in_array($finding->rule_key, ['content.shortener_links', 'content.comment_links', 'hygiene.autoloaded_secret_options'], true)) {
                $source = match ($finding->rule_key) {
                    'content.comment_links' => 'comments.approved',
                    'hygiene.autoloaded_secret_options' => 'options.values',
                    default => 'posts.content',
                };
                if ($this->surfaceComplete($state, $source)) {
                    $this->recorder->resolve($finding, $run, 'Not present in a complete sweep of '.$source.'.');
                    $resolved++;
                }

                continue;
            }

            $surface = $this->registry->get($surfaceKey);
            if (! $surface) {
                continue;
            }

            if (! $surface->isRows()) {
                // Inventory reads are fresh and whole within this sweep; every
                // surface the rule depends on must be complete.
                $deps = (array) ($rule['surfaces'] ?? []);
                $complete = $deps !== [] && collect($deps)->every(fn ($d) => ($state['inventory']['surfaces'][$d]['status'] ?? null) === 'complete');
                if ($complete) {
                    $this->recorder->resolve($finding, $run, 'Not present in a complete, compatible inventory read.');
                    $resolved++;
                }

                continue;
            }

            if (! $this->surfaceComplete($state, $surfaceKey) || ! in_array($surfaceKey, (array) $rule['surfaces'], true)) {
                continue;
            }
            if ($rechecks >= self::RECHECK_LIMIT) {
                $skipped++;

                continue;
            }
            $rowId = $finding->subject['row_id'] ?? null;
            if (! is_numeric($rowId)) {
                continue;
            }

            $rechecks++;
            $check = $this->scanner->recheck($reader, $surface, $schema, (int) $rowId, (string) ($finding->subject['field'] ?? ''), $finding->rule_key, $rules, $hosts);
            if ($check['missing']) {
                $this->recorder->resolve($finding, $run, 'The row no longer exists.');
                $resolved++;
            } elseif (! $check['hit'] && $check['fully_read']) {
                $this->recorder->resolve($finding, $run, 'Complete sweep and point recheck found no match.');
                $resolved++;
            }
        }

        if ($skipped > 0) {
            $this->log->log($run, 'warn', $skipped.' findings await verification; the recheck limit per sweep was reached.');
        }

        return $resolved;
    }

    private function surfaceComplete(array $state, string $key): bool
    {
        return ($state['surfaces'][$key]['status'] ?? null) === 'complete';
    }

    private function sweepSnapshotId(DbScanMarketRun $run): ?int
    {
        if (! $run->sweep_id) {
            return $run->snapshot_id;
        }

        return DbScanSnapshot::query()
            ->whereIn('market_run_id', DbScanMarketRun::query()->where('sweep_id', $run->sweep_id)->pluck('id'))
            ->orderByDesc('id')
            ->value('id');
    }

    private function sweepHit(string $rule, string $title, string $item, array $details, string $surface): Hit
    {
        return new Hit(
            $rule,
            $title,
            ['surface' => 'sweep', 'table' => $surface, 'item' => mb_substr($item, 0, 190), 'object_type' => 'market'],
            ['details' => $details, 'signals' => [$rule], 'excerpts' => []],
            null,
            null,
        );
    }
}
