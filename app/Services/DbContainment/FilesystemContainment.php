<?php

namespace App\Services\DbContainment;

use App\Models\DbContainmentFileObservation;
use App\Models\DbContainmentOperation;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Support\Str;

class FilesystemContainment
{
    public function __construct(private readonly ContainmentPolicy $policy, private readonly RestrictedHostTransport $host, private readonly ContainmentCrypto $crypto, private readonly ContainmentService $service, private readonly BackupVault $vault) {}

    public function diagnose(User $actor, Platform $platform): array
    {
        $this->policy->authorize($actor);
        $market = $this->policy->market($platform, 'filesystem');
        $result = $this->host->call($market, ['action' => 'diagnose']);
        $observations = [];
        foreach ($result['files'] ?? [] as $file) {
            $id = (string) Str::uuid();
            $identity = ['root_id' => $result['root_id'], 'file' => $file];
            $row = DbContainmentFileObservation::query()->create(['id' => $id, 'platform_id' => $platform->id, 'metadata' => ['path' => $file['path'], 'size' => $file['size'], 'mtime_ns' => $file['mtime_ns'], 'ctime_ns' => $file['ctime_ns'], 'mode' => $file['mode'], 'source' => 'Restricted account host helper, outside WordPress', 'zero_byte' => $file['size'] === 0], 'sealed_identity' => $this->crypto->seal($identity), 'observed_at' => now()]);
            $observations[] = $row->toArray();
        }

        return ['observations' => $observations, 'core' => $result['core'] ?? [], 'gaps' => $result['gaps'] ?? [], 'unregistered_discoveries' => $result['discovered_unregistered'] ?? [], 'coverage_scope' => 'registered filesystem root only'];
    }

    public function preview(User $actor, DbContainmentFileObservation $observation, string $key): DbContainmentOperation
    {
        $this->policy->authorize($actor);
        $existing = DbContainmentOperation::query()->where('actor_id', $actor->id)->where('request_key', $key)->first();
        if ($existing) {
            if ($existing->selection !== [$observation->id]) {
                throw new ContainmentException('idempotency_payload_mismatch');
            }

            return $existing;
        }
        if ($observation->observed_at->lt(now()->subMinutes(15))) {
            throw new ContainmentException('fresh_filesystem_diagnostic_required');
        }
        $platform = Platform::query()->findOrFail($observation->platform_id);
        $market = $this->policy->market($platform, 'quarantine');
        $identity = $this->crypto->open($observation->sealed_identity);
        $response = $this->host->call($market, ['action' => 'inspect', 'path' => $identity['file']['path']]);
        if ($response['identity'] !== $identity['file'] || $response['root_id'] !== $identity['root_id']) {
            throw new ContainmentException('filesystem_identity_changed');
        }
        $host = strtolower(preg_replace('/^www\./', '', parse_url(str_contains($platform->domain, '://') ? $platform->domain : 'https://'.$platform->domain, PHP_URL_HOST)));

        return $this->service->create($actor, $platform, $market, [$observation->id], ['identity' => $identity, 'action' => 'quarantine'], ['confirmation' => $host, 'privilege_confirmation' => null, 'privileged' => false, 'lines' => ['QUARANTINE '.$identity['file']['path'].' — '.$identity['file']['size'].' bytes; move outside every webroot, mode 000.', 'Preserve hash, original permissions/ownership/mtime and ctime evidence. No deletion.']], $key, null, 'filesystem');
    }

    public function restorePreview(User $actor, DbContainmentOperation $parent, string $key, array $manifest): DbContainmentOperation
    {
        $platform = Platform::query()->findOrFail($parent->platform_id);
        $market = $this->policy->market($platform, 'quarantine');
        $status = $this->host->call($market, ['action' => 'operation_status', 'operation_id' => $parent->id]);
        if (($status['journal']['status'] ?? '') !== 'complete' || empty($status['journal']['result']['quarantined'])) {
            throw new ContainmentException('quarantine_not_verified');
        }
        $manifest['action'] = 'restore';
        $manifest['original_operation'] = $parent->id;

        return $this->service->create($actor, $platform, $market, ['restore'], $manifest, ['confirmation' => $parent->preview['confirmation'], 'privilege_confirmation' => null, 'privileged' => false, 'lines' => ['RESTORE '.$manifest['identity']['file']['path'].' — re-enable its original permissions. The original path must be empty.', 'This can re-enable executable code; ctime cannot be restored.']], $key, null, 'filesystem', $parent->id);
    }

    public function execute(DbContainmentOperation $op, array $manifest): void
    {
        $market = $this->policy->market(Platform::query()->findOrFail($op->platform_id), 'quarantine');
        if (! $op->backup_id) {
            $backup = $this->vault->store($op, $manifest);
            $op->update(['backup_id' => $backup->id, 'status' => 'commit_intent']);
            app(ContainmentAudit::class)->record($op, 'backup_durable');
        } else {
            $journal = $this->host->call($market, ['action' => 'operation_status', 'operation_id' => $op->id]);
            if (($journal['journal']['status'] ?? '') === 'complete') {
                $op->update(['result' => $journal['journal']['result']]);

                return;
            }
            if ($journal['journal']) {
                throw new ContainmentException('host_outcome_unknown_manual_reconciliation');
            }
        }
        $request = ['action' => $manifest['action'], 'operation_id' => $op->id, 'identity' => $manifest['identity']['file']];
        if ($op->parent_id) {
            $request['original_operation'] = $manifest['original_operation'];
        }
        $result = $this->host->call($market, $request);
        $op->update(['status' => 'committed', 'result' => $result]);
        app(ContainmentAudit::class)->record($op, 'host_action_verified');
    }
}
