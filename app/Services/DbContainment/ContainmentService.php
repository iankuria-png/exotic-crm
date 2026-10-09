<?php

namespace App\Services\DbContainment;

use App\Models\DbContainmentBackup;
use App\Models\DbContainmentOperation;
use App\Models\DbScanFinding;
use App\Models\Platform;
use App\Models\User;
use App\Services\DbScanner\Engine\FindingRecorder;
use App\Services\MarketOperationCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContainmentService
{
    public function __construct(private readonly ContainmentCrypto $crypto, private readonly ContainmentPolicy $policy, private readonly ActionCatalog $catalog, private readonly BackupVault $vault, private readonly CacheVerificationAdapter $cache, private readonly ContainmentAudit $audit) {}

    private function writer(Platform $platform, $market): MarketDbWriter
    {
        $writer = app(MarketDbWriter::class);
        $writer->connect($platform, $market);

        return $writer;
    }

    public function fingerprint(Platform $platform, $market): string
    {
        return $this->crypto->digest([$platform->only(['id', 'domain', 'db_host', 'db_name', 'db_user', 'db_pass', 'db_prefix']), $market->configuration, \App\Models\DbScanList::query()->whereIn('key', ['allow.it_emails', 'allow.admin_emails'])->orderBy('key')->get(['key', 'entries'])->toArray()], 'credentials-policy');
    }

    public function preview(User $actor, DbScanFinding $finding, array $actions, string $requestKey): DbContainmentOperation
    {
        $this->policy->authorize($actor);
        $finding->refresh();
        $actions = array_values(array_unique($actions));
        sort($actions);
        $existing = DbContainmentOperation::query()->where('actor_id', $actor->id)->where('request_key', $requestKey)->first();
        if ($existing) {
            if ((int) $existing->finding_id !== (int) $finding->id || $existing->selection !== $actions) {
                throw new ContainmentException('idempotency_payload_mismatch');
            }

            return $existing;
        }
        $platform = Platform::query()->findOrFail($finding->platform_id);
        $market = $this->policy->market($platform);
        $writer = $this->writer($platform, $market);
        try {
            $selector = $this->catalog->selector($finding, $actions, $writer->identity);
            if (in_array('remove_hidden_link', $actions, true)) {
                $selector['option_names'] = [$writer->optionName((int) $selector['finding_row_id']), 'sidebars_widgets'];
            }
            $before = $writer->begin($selector);
            $plan = $this->catalog->plan($finding, $actions, $writer->identity, $before, $market->configuration ?? []);
            $writer->rollback();
            $this->cache->call($platform, $market, (string) Str::uuid(), str_repeat('0', 64), $plan['cache']);
            $manifest = ['identity' => $writer->identity, 'selector' => $selector, 'before' => MarketDbWriter::encode($before), 'after' => MarketDbWriter::encode($plan['after']), 'cache' => $plan['cache'], 'finding_revision' => [$finding->last_run_id, $finding->rule_version_hash, $finding->latest_observation_id]];

            return $this->create($actor, $platform, $market, $actions, $manifest, ['lines' => ['DRY-RUN on '.$writer->identity['schema'].' (prefix '.$writer->identity['prefix'].') at '.now()->utc()->toIso8601String(), ...$plan['lines'], 'Dry-run only. Nothing changed.'], 'diff' => $plan['diff'], 'privileged' => $plan['privileged'], 'confirmation' => $writer->identity['host'], 'privilege_confirmation' => $plan['privileged'] ? 'PRIVILEGED '.$writer->identity['host'].' '.count($selector['user_ids'] ?? []) : null, 'consequences' => 'Password reset remains possible. Unselected application passwords remain active. Restore may re-enable access.'], $requestKey, $finding->id);
        } finally {
            $writer->close();
        }
    }

    public function staffPreview(User $actor, Platform $platform, string $key): DbContainmentOperation
    {
        $this->policy->authorize($actor);
        $market = $this->policy->market($platform);
        $existing = DbContainmentOperation::query()->where('actor_id', $actor->id)->where('request_key', $key)->first();
        if ($existing) {
            if ((int) $existing->platform_id !== (int) $platform->id || $existing->selection !== ['end_sessions']) {
                throw new ContainmentException('idempotency_payload_mismatch');
            }

            return $existing;
        }
        $writer = $this->writer($platform, $market);
        try {
            $ids = $writer->staffIds();
            $selector = ['user_ids' => $ids, 'option_names' => [$writer->identity['prefix'].'user_roles']];
            $before = $writer->begin($selector);
            $after = $before;
            $lines = [];
            foreach ($before['users'] as $user) {
                $metadata = array_values(array_filter($before['usermeta'], fn ($row) => (int) $row['user_id'] === (int) $user['ID']));
                $finding = new DbScanFinding(['rule_key' => 'access.activity_behaviour', 'evidence' => ['details' => ['user_id' => (int) $user['ID'], 'login' => $user['user_login']]]]);
                $subset = ['users' => [$user], 'usermeta' => $metadata, 'options' => $before['options']];
                $planned = $this->catalog->plan($finding, ['end_sessions'], $writer->identity, $subset, $market->configuration ?? []);
                $lines = array_merge($lines, $planned['lines']);
            }
            $after['usermeta'] = array_values(array_filter($after['usermeta'], fn ($row) => $row['meta_key'] !== 'session_tokens'));
            $writer->rollback();
            $cache = ['user_ids' => $ids, 'option_names' => [], 'session_check' => true, 'content_check' => false];
            $this->cache->call($platform, $market, (string) Str::uuid(), str_repeat('0', 64), $cache);
            $manifest = ['identity' => $writer->identity, 'selector' => $selector, 'before' => MarketDbWriter::encode($before), 'after' => MarketDbWriter::encode($after), 'cache' => $cache];

            return $this->create($actor, $platform, $market, ['end_sessions'], $manifest, ['lines' => $lines, 'confirmation' => $writer->identity['host'], 'privilege_confirmation' => 'PRIVILEGED '.$writer->identity['host'].' '.count($ids), 'privileged' => true, 'consequences' => 'Explicit staff logout includes protected staff. Passwords and all application keys remain unchanged. The account set is frozen to these IDs.'], $key);
        } finally {
            $writer->close();
        }
    }

    public function previewGroup(User $actor, array $group, string $key): DbContainmentOperation
    {
        if (count($group) === 1) {
            return $this->preview($actor, $group[0][0], $group[0][1], $key);
        }
        $this->policy->authorize($actor);
        $existing = DbContainmentOperation::query()->where('actor_id', $actor->id)->where('request_key', $key)->first();
        if ($existing) {
            if (($this->crypto->open($existing->sealed_intent)['group_input'] ?? null) !== array_map(fn ($g) => [$g[0]->id, $g[1]], $group)) {
                throw new ContainmentException('idempotency_payload_mismatch');
            }

            return $existing;
        }
        $platform = Platform::query()->findOrFail($group[0][0]->platform_id);
        $market = $this->policy->market($platform);
        $writer = $this->writer($platform, $market);
        try {
            $selectors = [];
            $ids = [];
            $actions = [];
            $targets = [];
            foreach ($group as [$finding,$selected]) {
                if ($finding->platform_id !== $platform->id || $finding->rule_key !== 'access.application_passwords') {
                    throw new ContainmentException('campaign_group_requires_planted_key_findings');
                }
                $selector = $this->catalog->selector($finding, $selected, $writer->identity);
                $ids = array_merge($ids, $selector['user_ids']);
                $targets[] = ['finding_id' => $finding->id, 'actions' => $selected, 'revision' => [$finding->last_run_id, $finding->rule_version_hash, $finding->latest_observation_id]];
                $actions = array_merge($actions, $selected);
            }
            $ids = array_values(array_unique($ids));
            sort($ids);
            $selector = ['user_ids' => $ids, 'option_names' => [$writer->identity['prefix'].'user_roles']];
            $before = $writer->begin($selector);
            $after = $before;
            $lines = [];
            $privileged = false;
            foreach ($group as [$finding,$selected]) {
                $uid = (int) ($finding->evidence['details']['user_id'] ?? 0);
                $subset = ['users' => array_values(array_filter($after['users'], fn ($r) => (int) $r['ID'] === $uid)), 'usermeta' => array_values(array_filter($after['usermeta'], fn ($r) => (int) $r['user_id'] === $uid)), 'options' => $before['options']];
                // Resolve the observed key before previous selected actions remove it.
                $original = ['users' => array_values(array_filter($before['users'], fn ($r) => (int) $r['ID'] === $uid)), 'usermeta' => array_values(array_filter($before['usermeta'], fn ($r) => (int) $r['user_id'] === $uid)), 'options' => $before['options']];
                $plan = $this->catalog->plan($finding, $selected, $writer->identity, $original, $market->configuration ?? []);
                $merged = $this->catalog->mergeChanges($original, $plan['after'], $subset);
                $after['users'] = array_merge(array_values(array_filter($after['users'], fn ($r) => (int) $r['ID'] !== $uid)), $merged['users']);
                $after['usermeta'] = array_merge(array_values(array_filter($after['usermeta'], fn ($r) => (int) $r['user_id'] !== $uid)), $merged['usermeta']);
                $lines = array_merge($lines, $plan['lines']);
                $privileged = $privileged || $plan['privileged'];
            }
            usort($after['users'], fn ($a, $b) => (int) $a['ID'] <=> (int) $b['ID']);
            usort($after['usermeta'], fn ($a, $b) => (int) $a['umeta_id'] <=> (int) $b['umeta_id']);
            $writer->rollback();
            $cache = ['user_ids' => $ids, 'option_names' => [], 'session_check' => (bool) array_intersect($actions, ['lock_account', 'end_sessions']), 'content_check' => false];
            $this->cache->call($platform, $market, (string) Str::uuid(), str_repeat('0', 64), $cache);
            $manifest = ['identity' => $writer->identity, 'selector' => $selector, 'before' => MarketDbWriter::encode($before), 'after' => MarketDbWriter::encode($after), 'cache' => $cache, 'targets' => $targets, 'group_input' => array_map(fn ($g) => [$g[0]->id, $g[1]], $group)];

            return $this->create($actor, $platform, $market, array_values(array_unique($actions)), $manifest, ['lines' => $lines, 'privileged' => $privileged, 'confirmation' => $writer->identity['host'], 'privilege_confirmation' => $privileged ? 'PRIVILEGED '.$writer->identity['host'].' '.count($ids) : null], $key);
        } finally {
            $writer->close();
        }
    }

    public function create(User $actor, Platform $platform, $market, array $selection, array $manifest, array $preview, string $key, ?int $finding = null, string $kind = 'database', ?string $parent = null): DbContainmentOperation
    {
        return DB::transaction(function () use ($actor, $platform, $market, $selection, $manifest, $preview, $key, $finding, $kind, $parent) {
            $op = DbContainmentOperation::query()->create(['id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'platform_id' => $platform->id, 'finding_id' => $finding, 'parent_id' => $parent, 'request_key' => $key, 'kind' => $kind, 'selection' => $selection, 'preview' => $preview, 'sealed_intent' => $this->crypto->seal($manifest), 'preview_digest' => $this->crypto->digest([$manifest, $preview, $selection]), 'credential_fingerprint' => $this->fingerprint($platform, $market), 'policy_version' => config('db_containment.policy_version'), 'expires_at' => now()->addSeconds(config('db_containment.approval_seconds')), 'status' => 'preview']);
            foreach (array_unique(array_filter([$finding, ...array_column($manifest['targets'] ?? [], 'finding_id')])) as $findingId) {
                DB::table('db_containment_operation_findings')->insert(['operation_id' => $op->id, 'finding_id' => $findingId]);
            }
            $this->audit->record($op, 'preview_created');

            return $op;
        });
    }

    public function confirm(User $actor, DbContainmentOperation $operation, string $phrase, ?string $privileged, string $digest): DbContainmentOperation
    {
        $this->policy->authorize($actor);
        $op = DB::transaction(function () use ($actor, $operation, $phrase, $privileged, $digest) {
            MarketOperationCoordinator::lock([0, (int) $operation->platform_id]);
            $op = DbContainmentOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($op->campaign_id || str_starts_with($op->request_key, 'campaign:')) {
                throw new ContainmentException('campaign_confirmation_required');
            }
            $this->validateApproval($actor, $op, $phrase, $privileged, $digest);
            if ($op->status === 'preview') {
                $this->approve($op);
            }

            return $op;
        });
        $this->publish();

        return $op->fresh();
    }

    public function validateApproval(User $actor, DbContainmentOperation $op, string $phrase, ?string $privileged, string $digest): void
    {
        if ((int) $op->actor_id !== (int) $actor->id || ! hash_equals($op->preview_digest, $digest) || $phrase !== $op->preview['confirmation'] || ($op->preview['privilege_confirmation'] ?? null) !== ($privileged ?: null)) {
            throw new ContainmentException('confirmation_does_not_match_preview');
        }
        $platform = Platform::query()->findOrFail($op->platform_id);
        $market = $this->policy->market($platform, $op->kind === 'filesystem' ? 'quarantine' : 'database');
        if (! hash_equals($op->credential_fingerprint, $this->fingerprint($platform, $market)) || $op->policy_version !== config('db_containment.policy_version')) {
            throw new ContainmentException('preview_policy_or_credentials_changed');
        }
        if ($op->status === 'preview' && $op->expires_at->isPast()) {
            throw new ContainmentException('preview_expired');
        }
        if (in_array($op->status, ['cancelled', 'expired'], true)) {
            throw new ContainmentException('operation_no_longer_approvable');
        }
    }

    public function approve(DbContainmentOperation $op): void
    {
        $lease = DB::table('db_market_operation_leases')->where('platform_id', $op->platform_id)->first();
        if ($lease->operation_id && $lease->operation_id !== $op->id) {
            throw new ContainmentException('another_market_operation_pending');
        }
        DB::table('db_market_operation_leases')->where('platform_id', $op->platform_id)->update(['operation_id' => $op->id, 'updated_at' => now()]);
        $op->update(['status' => 'approved', 'approved_at' => now(), 'expires_at' => now()->addSeconds(config('db_containment.approval_seconds'))]);
        DB::table('db_containment_outbox')->insertOrIgnore(['operation_id' => $op->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($op, 'approval_recorded');
    }

    public function publish(): void
    {
        foreach (DB::table('db_containment_outbox')->whereNull('published_at')->whereNull('cancelled_at')->limit(100)->get() as $row) {
            \App\Jobs\DbContainment\ContainmentJob::dispatch($row->operation_id)->onQueue(config('db_containment.queue'));
            DB::table('db_containment_outbox')->where('operation_id', $row->operation_id)->update(['published_at' => now()]);
        }
    }

    public function restoreManifest(array $manifest): array
    {
        $before = MarketDbWriter::decode($manifest['before']);
        $after = MarketDbWriter::decode($manifest['after']);
        $selector = [];
        $old = [];
        $new = [];
        if (isset($before['users'])) {
            $columns = ['ID', 'user_login', 'user_email', 'user_registered'];
            foreach ($before['users'] as $i => $row) {
                foreach ($row as $column => $value) {
                    if ($value !== $after['users'][$i][$column]) {
                        $columns[] = $column;
                    }
                }
            }
            $columns = array_values(array_unique($columns));
            $old['users'] = array_map(fn ($r) => array_intersect_key($r, array_flip($columns)), $before['users']);
            $new['users'] = array_map(fn ($r) => array_intersect_key($r, array_flip($columns)), $after['users']);
            $selector['user_ids'] = array_column($before['users'], 'ID');
            $selector['user_columns'] = $columns;
            $oldMeta = array_column($before['usermeta'], null, 'umeta_id');
            $newMeta = array_column($after['usermeta'], null, 'umeta_id');
            $keys = [];
            foreach ($oldMeta as $id => $row) {
                if (($newMeta[$id] ?? null) !== $row) {
                    $keys[] = $row['meta_key'];
                }
            }
            $selector['meta_keys'] = array_values(array_unique($keys));
            foreach (['before' => 'old', 'after' => 'new'] as $side => $variable) {
                ${$variable}['usermeta'] = array_values(array_filter(${$side}['usermeta'], fn ($r) => in_array($r['meta_key'], $selector['meta_keys'], true)));
            }
        }
        $names = [];
        foreach ($before['options'] ?? [] as $i => $row) {
            if ($row !== $after['options'][$i]) {
                $names[] = $row['option_name'];
            }
        }
        if ($names) {
            $selector['option_names'] = $names;
            $old['options'] = array_values(array_filter($before['options'], fn ($r) => in_array($r['option_name'], $names, true)));
            $new['options'] = array_values(array_filter($after['options'], fn ($r) => in_array($r['option_name'], $names, true)));
        }
        $manifest['cache']['meta_keys'] = $selector['meta_keys'] ?? [];
        $manifest['selector'] = $selector;
        $manifest['before'] = MarketDbWriter::encode($old);
        $manifest['after'] = MarketDbWriter::encode($new);
        unset($manifest['finding_revision']);
        if (isset($manifest['targets'])) {
            foreach ($manifest['targets'] as &$target) {
                $target['actions'] = ['restore'];
            }
        }

        return $manifest;
    }

    public function restorePreview(User $actor, DbContainmentOperation $parent, string $key): DbContainmentOperation
    {
        $this->policy->authorize($actor);
        if (! in_array($parent->status, ['verified', 'cache_pending'], true) || ! $parent->backup_id) {
            throw new ContainmentException('operation_not_restorable');
        }
        $existing = DbContainmentOperation::query()->where('actor_id', $actor->id)->where('request_key', $key)->first();
        if ($existing) {
            if ($existing->parent_id !== $parent->id) {
                throw new ContainmentException('idempotency_payload_mismatch');
            }

            return $existing;
        }
        $data = $this->vault->read(DbContainmentBackup::query()->findOrFail($parent->backup_id));
        $manifest = $data['manifest'];
        if ($parent->kind === 'database') {
            $manifest = $this->restoreManifest($manifest);
        }
        $platform = Platform::query()->findOrFail($parent->platform_id);
        $market = $this->policy->market($platform, $parent->kind === 'filesystem' ? 'quarantine' : 'database');
        if ($parent->kind === 'filesystem') {
            return app(FilesystemContainment::class)->restorePreview($actor, $parent, $key, $manifest);
        }
        $writer = $this->writer($platform, $market);
        try {
            $current = MarketDbWriter::encode($writer->begin($manifest['selector']));
            $writer->rollback();
            if ($current !== $manifest['after'] || $writer->identity !== $manifest['identity']) {
                throw new ContainmentException('restore_conflict_current_rows_changed');
            }
            $this->cache->call($platform, $market, (string) Str::uuid(), str_repeat('0', 64), $manifest['cache']);
            [$manifest['before'],$manifest['after']] = [$manifest['after'], $manifest['before']];

            return $this->create($actor, $platform, $market, ['restore'], $manifest, ['lines' => ['RESTORE exact changed rows from encrypted backup; old sessions and keys may regain access.'], 'confirmation' => $parent->preview['confirmation'], 'privilege_confirmation' => $parent->preview['privilege_confirmation'] ?? null, 'privileged' => $parent->preview['privileged'] ?? false], $key, $parent->finding_id, 'database', $parent->id);
        } finally {
            $writer->close();
        }
    }

    public function cancel(User $actor, DbContainmentOperation $operation): DbContainmentOperation
    {
        $this->policy->authorize($actor);

        return DB::transaction(function () use ($operation) {
            MarketOperationCoordinator::lock([0, (int) $operation->platform_id]);
            $op = DbContainmentOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (in_array($op->status, ['preview', 'approved', 'waiting_for_scan', 'waiting_for_paused_scan'], true)) {
                $op->update(['status' => 'cancelled']);
                DB::table('db_containment_outbox')->where('operation_id', $op->id)->update(['cancelled_at' => now()]);
                $this->release($op, true);
            } elseif (! in_array($op->status, ['verified', 'restored', 'cancelled', 'expired'], true)) {
                $op->update(['cancel_requested_at' => now()]);
            }
            $this->audit->record($op, 'cancel_requested');

            return $op;
        });
    }

    public function release(DbContainmentOperation $op, bool $terminal, ?string $token = null): void
    {
        DB::transaction(function () use ($op, $terminal, $token) {
            MarketOperationCoordinator::lock([0, (int) $op->platform_id]);
            $query = fn () => DB::table('db_market_operation_leases')->where('operation_id', $op->id)->when($token, fn ($q) => $q->where('owner_token', $token));
            $query()->where('platform_id', 0)->update(['owner_token' => null, 'expires_at' => null, 'operation_id' => null, 'updated_at' => now()]);
            $query()->where('platform_id', $op->platform_id)->update(['owner_token' => null, 'expires_at' => null, 'operation_id' => $terminal ? null : $op->id, 'updated_at' => now()]);
        });
    }

    public function finish(DbContainmentOperation $op, string $token): void
    {
        DB::transaction(function () use ($op, $token) {
            MarketOperationCoordinator::lock([0, (int) $op->platform_id]);
            if (DB::table('db_market_operation_leases')->whereIn('platform_id', [0, $op->platform_id])->where('operation_id', $op->id)->where('owner_token', $token)->where('expires_at', '>', now())->count() !== 2) {
                throw new ContainmentException('lease_lost');
            }
            $op->update(['status' => 'verified', 'result_code' => 'verified', 'result' => array_merge($op->result ?? [], ['lines' => ['Backup created (encrypted).', ...array_values(array_filter($op->preview['lines'], fn ($line) => ! str_starts_with($line, 'DRY-RUN') && $line !== 'Dry-run only. Nothing changed.')), 'All changes verified.'], 'no_op' => $op->kind === 'database' && ($this->crypto->open($op->sealed_intent)['before'] === $this->crypto->open($op->sealed_intent)['after']), 'database_verified' => $op->kind === 'database', 'cache_verified' => $op->kind === 'database' ? true : null, 'filesystem_verified' => $op->kind === 'filesystem'])]);
            $manifest = $this->crypto->open($op->sealed_intent);
            $targets = $manifest['targets'] ?? ($op->finding_id ? [['finding_id' => $op->finding_id, 'actions' => $op->selection]] : []);
            foreach ($targets as $target) {
                $finding = DbScanFinding::query()->find($target['finding_id']);
                if (! $finding) {
                    continue;
                }
                $remediated = match ($finding->rule_key) {
                    'access.application_passwords' => in_array('revoke_app_password', $target['actions'], true),
                    'content.hidden_text' => in_array('remove_hidden_link', $target['actions'], true),
                    'access.hidden_admin_capabilities' => in_array('clear_user_level', $target['actions'], true),
                    'persistence.invisible_plugins' => in_array('deactivate_dangling_plugin_entry', $target['actions'], true),
                    default => false,
                };
                $from = $finding->status;
                $finding->update(['status' => $op->parent_id || ! $remediated ? 'acknowledged' : 'contained']);
                app(FindingRecorder::class)->event($finding, null, $op->parent_id ? 'restored' : 'containment_verified', $from, $finding->status, 'Containment operation '.$op->id, $op->actor_id);
            }
            $this->audit->record($op, 'verification_complete');
            $this->release($op, true, $token);
        });
        try {
            if (config('db_scanner.enabled')) {
                app(\App\Services\DbScanner\Engine\PassController::class)->start([(int) $op->platform_id], 'standard', 'manual', (int) $op->actor_id, idempotencyKey: 'containment:'.$op->id);
            }
        } catch (\Throwable) { /* Verified operation remains durable; operator can queue a scan. */
        }
    }
}
