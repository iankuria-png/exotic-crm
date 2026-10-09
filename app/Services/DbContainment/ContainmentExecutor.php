<?php

namespace App\Services\DbContainment;

use App\Models\DbContainmentBackup;
use App\Models\DbContainmentCampaign;
use App\Models\DbContainmentOperation;
use App\Models\DbScanFinding;
use App\Models\Platform;
use App\Models\User;
use App\Services\MarketOperationCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContainmentExecutor
{
    public function __construct(private readonly ContainmentService $service, private readonly ContainmentCrypto $crypto, private readonly ContainmentPolicy $policy, private readonly BackupVault $vault, private readonly CacheVerificationAdapter $cache, private readonly ContainmentAudit $audit) {}

    private function claim(DbContainmentOperation $operation): ?string
    {
        return DB::transaction(function () use ($operation) {
            MarketOperationCoordinator::lock([0, (int) $operation->platform_id]);
            $op = DbContainmentOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (! in_array($op->status, ['approved', 'waiting_for_scan', 'waiting_for_paused_scan', 'executing', 'commit_intent', 'outcome_unknown', 'committed', 'cache_pending', 'recovery_pending'], true)) {
                return null;
            }
            $market = DB::table('db_market_operation_leases')->where('platform_id', $op->platform_id)->first();
            if ($market->operation_id !== $op->id) {
                throw new ContainmentException('operation_ownership_lost');
            }
            if (! $op->backup_id && $op->expires_at->isPast()) {
                $op->update(['status' => 'expired']);
                $this->service->release($op, true);
                $this->audit->record($op, 'approval_expired');

                return null;
            }
            $run = MarketOperationCoordinator::scanClaim((int) $op->platform_id);
            if ($run) {
                $op->update(['status' => $run->status === 'paused' ? 'waiting_for_paused_scan' : 'waiting_for_scan']);

                return null;
            }
            $global = DB::table('db_market_operation_leases')->where('platform_id', 0)->first();
            if (($global->owner_token && $global->expires_at > now()->toDateTimeString()) || ($market->owner_token && $market->expires_at > now()->toDateTimeString())) {
                return null;
            }
            $token = (string) Str::uuid();
            foreach ([0, (int) $op->platform_id] as $id) {
                DB::table('db_market_operation_leases')->where('platform_id', $id)->update(['operation_id' => $op->id, 'owner_token' => $token, 'expires_at' => now()->addSeconds(config('db_containment.lease_seconds')), 'generation' => DB::raw('generation + 1'), 'updated_at' => now()]);
            }
            if (! $op->backup_id) {
                $op->update(['status' => 'executing']);
            }
            $this->audit->record($op, 'execution_claimed');

            return $token;
        });
    }

    private function fence(DbContainmentOperation $op, string $token): void
    {
        DB::transaction(function () use ($op, $token) {
            MarketOperationCoordinator::lock([0, (int) $op->platform_id]);
            $held = DB::table('db_market_operation_leases')->whereIn('platform_id', [0, $op->platform_id])->where('operation_id', $op->id)->where('owner_token', $token)->where('expires_at', '>', now())->count();
            if ($held !== 2) {
                throw new ContainmentException('lease_lost');
            }
            DB::table('db_market_operation_leases')->where('owner_token', $token)->where('operation_id', $op->id)->update(['expires_at' => now()->addSeconds(config('db_containment.lease_seconds')), 'updated_at' => now()]);
        });
    }

    public function execute(string $id): void
    {
        $op = DbContainmentOperation::query()->findOrFail($id);
        $token = $this->claim($op);
        if (! $token) {
            return;
        }$op->refresh();
        $writer = null;
        $commitAttempted = false;
        $terminal = false;
        try {
            $this->policy->authorize(User::query()->find($op->actor_id));
            $platform = Platform::query()->findOrFail($op->platform_id);
            $market = $this->policy->market($platform, $op->kind === 'filesystem' ? 'quarantine' : 'database');
            if (! hash_equals($op->credential_fingerprint, $this->service->fingerprint($platform, $market)) || $op->policy_version !== config('db_containment.policy_version')) {
                throw new ContainmentException('approved_configuration_changed');
            }
            if ($op->campaign_id) {
                $campaign = DbContainmentCampaign::query()->findOrFail($op->campaign_id);
                if (! hash_equals($campaign->preview_digest, $this->crypto->digest($campaign->members, 'campaign'))) {
                    throw new ContainmentException('campaign_membership_changed');
                }
                if (! $campaign->approved_at || ! collect($campaign->members)->contains(fn ($m) => $m['id'] === $op->id && $m['digest'] === $op->preview_digest)) {
                    throw new ContainmentException('campaign_binding_invalid');
                }
            }
            $manifest = $this->crypto->open($op->sealed_intent);
            if ($op->kind === 'filesystem') {
                $this->fence($op, $token);
                app(FilesystemContainment::class)->execute($op, $manifest);
                $this->service->finish($op, $token);

                return;
            }
            $writer = app(MarketDbWriter::class);
            $writer->connect($platform, $market);
            if ($writer->identity !== $manifest['identity']) {
                throw new ContainmentException('approved_site_identity_changed');
            }
            $current = MarketDbWriter::encode($writer->begin($manifest['selector']));
            if ($op->backup_id) {
                $stored = $this->vault->read(DbContainmentBackup::query()->findOrFail($op->backup_id), true);
                if ($stored['platform_id'] !== $op->platform_id || $stored['manifest'] !== $manifest) {
                    throw new ContainmentException('backup_intent_mismatch');
                }
                $writer->rollback();
                if ($current === $manifest['before']) {
                    $op->update(['status' => 'not_applied', 'result_code' => 'recovered_not_applied']);
                    $terminal = true;
                    $this->audit->record($op, 'recovered_not_applied');

                    return;
                }
                if ($current !== $manifest['after']) {
                    throw new ContainmentException('recovery_conflict_manual_review');
                }
                $op->update(['status' => 'committed']);
                $this->audit->record($op, 'recovered_committed');
            } else {
                if ($current !== $manifest['before']) {
                    throw new ContainmentException('preview_stale_rows_changed');
                }
                if ($op->finding_id && ! $op->parent_id) {
                    $finding = DbScanFinding::query()->findOrFail($op->finding_id);
                    if (! in_array($finding->status, ['open', 'acknowledged'], true) || [$finding->last_run_id, $finding->rule_version_hash, $finding->latest_observation_id] !== $manifest['finding_revision']) {
                        throw new ContainmentException('finding_changed_since_preview');
                    }
                    // Recheck current protections/capabilities without adopting the newly generated lock hash.
                    app(ActionCatalog::class)->plan($finding, $op->selection, $writer->identity, MarketDbWriter::decode($current), $market->configuration ?? []);
                }
                foreach ($op->parent_id ? [] : ($manifest['targets'] ?? []) as $target) {
                    $finding = DbScanFinding::query()->findOrFail($target['finding_id']);
                    if (! in_array($finding->status, ['open', 'acknowledged'], true) || [$finding->last_run_id, $finding->rule_version_hash, $finding->latest_observation_id] !== $target['revision']) {
                        throw new ContainmentException('campaign_finding_changed_since_preview');
                    }
                    $uid = (int) $finding->evidence['details']['user_id'];
                    $state = MarketDbWriter::decode($current);
                    $subset = ['users' => array_values(array_filter($state['users'], fn ($r) => (int) $r['ID'] === $uid)), 'usermeta' => array_values(array_filter($state['usermeta'], fn ($r) => (int) $r['user_id'] === $uid)), 'options' => $state['options']];
                    app(ActionCatalog::class)->plan($finding, $target['actions'], $writer->identity, $subset, $market->configuration ?? []);
                }
                $this->cache->call($platform, $market, $op->id, $op->preview_digest, $manifest['cache']);
                $backup = $this->vault->store($op, $manifest);
                $op->update(['backup_id' => $backup->id]);
                $this->audit->record($op, 'backup_durable');
                $this->fence($op, $token);
                $op->update(['status' => 'commit_intent']);
                $this->audit->record($op, 'commit_intent');
                $writer->mutate(MarketDbWriter::decode($manifest['before']), MarketDbWriter::decode($manifest['after']));
                if (MarketDbWriter::encode($writer->snapshot($manifest['selector'], true)) !== $manifest['after']) {
                    throw new ContainmentException('transaction_verification_failed');
                }
                $this->fence($op, $token);
                $commitAttempted = true;
                $writer->commit();
                $op->update(['status' => 'committed']);
                $this->audit->record($op, 'remote_commit_recorded');
                if (MarketDbWriter::encode($writer->snapshot($manifest['selector'])) !== $manifest['after']) {
                    throw new ContainmentException('post_commit_verification_changed');
                }
            }
            $this->fence($op, $token);
            try {
                $this->cache->call($platform, $market, $op->id, $op->preview_digest, $manifest['cache'], MarketDbWriter::decode($manifest['after']), true);
            } catch (ContainmentException $e) {
                $op->update(['status' => 'cache_pending', 'result_code' => $e->reason, 'result' => ['database_verified' => true, 'cache_verified' => false, 'lines' => ['Database changes verified. WordPress/cache verification pending.']]]);
                $this->audit->record($op, 'cache_pending', $e->reason);

                return;
            }
            $this->service->finish($op, $token);
            $terminal = true;
        } catch (\Throwable $e) {
            $writer?->rollback();
            if (DB::table('db_market_operation_leases')->where('platform_id', $op->platform_id)->where('owner_token', $token)->where('operation_id', $op->id)->doesntExist()) {
                return;
            }
            $reason = $e instanceof ContainmentException ? $e->reason : 'execution_error_redacted';
            $status = $commitAttempted ? 'outcome_unknown' : ($op->backup_id ? 'recovery_pending' : 'conflict');
            if (! $op->backup_id) {
                $terminal = true;
            }
            $op->update(['status' => $status, 'result_code' => $reason, 'result' => ['lines' => ['Operation requires review: '.str_replace('_', ' ', $reason)]]]);
            $this->audit->record($op, 'execution_requires_review', $reason);
        } finally {
            $writer?->close();
            $this->service->release($op, $terminal || $op->fresh()->status === 'verified', $token);
        }
    }
}
