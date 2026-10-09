<?php

namespace App\Services\DbContainment;

use App\Models\DbContainmentBackup;
use App\Models\DbContainmentOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BackupVault
{
    public function __construct(private readonly ContainmentCrypto $crypto) {}

    private function directory(): string
    {
        $dir = rtrim((string) config('db_containment.vault'), '/');
        if ($dir === '' || is_link($dir)) {
            throw new ContainmentException('unsafe_private_storage');
        }
        if (! is_dir($dir) && ! mkdir($dir, 0700, true)) {
            throw new ContainmentException('private_storage_unavailable');
        }
        $real = realpath($dir);
        if (! $real || str_starts_with($real.'/', realpath(public_path()).'/')) {
            throw new ContainmentException('unsafe_private_storage');
        }
        if (! chmod($dir, 0700)) {
            throw new ContainmentException('private_storage_permissions');
        }

        return $real;
    }

    private function syncDirectory(string $dir): void
    {
        $stream = @fopen($dir, 'r');
        if (! $stream || ! @fsync($stream)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new ContainmentException('directory_fsync_unavailable', 503);
        }
        fclose($stream);
    }

    public function store(DbContainmentOperation $operation, array $manifest): DbContainmentBackup
    {
        $dir = $this->directory();
        $this->syncDirectory($dir); // capability probe before any market mutation
        $id = (string) Str::uuid();
        $cipher = $this->crypto->seal(['schema_version' => 1, 'operation_id' => $operation->id, 'platform_id' => $operation->platform_id, 'manifest' => $manifest]);
        $temp = $dir.'/'.$id.'.tmp';
        $path = $dir.'/'.$id.'.sealed';
        $stream = @fopen($temp, 'x+b');
        if (! $stream || ! chmod($temp, 0600)) {
            throw new ContainmentException('backup_create_failed');
        }
        try {
            $written = 0;
            while ($written < strlen($cipher)) {
                $n = fwrite($stream, substr($cipher, $written));
                if (! $n) {
                    throw new ContainmentException('backup_short_write');
                }
                $written += $n;
            }
            if (! fflush($stream) || ! fsync($stream)) {
                throw new ContainmentException('backup_fsync_failed');
            }
        } finally {
            fclose($stream);
        }
        if (! rename($temp, $path)) {
            throw new ContainmentException('backup_publish_failed');
        }
        $this->syncDirectory($dir);
        if (file_get_contents($path) !== $cipher) {
            throw new ContainmentException('backup_reread_failed');
        }
        $this->crypto->open($cipher);

        return DbContainmentBackup::query()->create(['id' => $id, 'operation_id' => $operation->id, 'storage_key' => $id.'.sealed', 'key_version' => config('db_containment.key_version'), 'cipher_digest' => hash('sha256', $cipher), 'byte_size' => strlen($cipher), 'expires_at' => now()->addDays((int) config('db_containment.retention_days'))]);
    }

    public function read(DbContainmentBackup $backup, bool $recovery = false): array
    {
        if ($backup->purged_at || (! $recovery && $backup->expires_at->isPast())) {
            throw new ContainmentException('restore_backup_expired_or_purged');
        }
        if (! preg_match('/^[a-f0-9-]{36}\.sealed$/', $backup->storage_key)) {
            throw new ContainmentException('invalid_backup_identity');
        }
        $path = $this->directory().'/'.$backup->storage_key;
        if (is_link($path) || ! is_file($path)) {
            throw new ContainmentException('backup_missing');
        }
        $cipher = file_get_contents($path);
        if (! hash_equals($backup->cipher_digest, hash('sha256', $cipher))) {
            throw new ContainmentException('backup_tampered');
        }
        $data = $this->crypto->open($cipher);
        if ($data['operation_id'] !== $backup->operation_id) {
            throw new ContainmentException('backup_operation_mismatch');
        }

        return $data;
    }

    public function purge(DbContainmentOperation $operation): void
    {
        DB::transaction(function () use ($operation) {
            $op = DbContainmentOperation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if (! in_array($op->status, ['verified', 'restored', 'cancelled', 'expired', 'not_applied'], true)
                || DbContainmentOperation::query()->where('parent_id', $op->id)->whereNotIn('status', ['verified', 'restored', 'cancelled', 'expired', 'not_applied'])->exists()) {
                throw new ContainmentException('backup_pinned_for_recovery');
            }
            // A retained restore child needs the parent's evidence until its own retention expires.
            if (DbContainmentOperation::query()->where('parent_id', $op->id)->whereHas('backup', fn ($q) => $q->whereNull('purged_at'))->exists()) {
                throw new ContainmentException('backup_pinned_by_restore');
            }
            $backup = DbContainmentBackup::query()->where('operation_id', $op->id)->lockForUpdate()->first();
            if (! $backup || $backup->purged_at) {
                return;
            }
            $path = $this->directory().'/'.$backup->storage_key;
            if (is_link($path) || (is_file($path) && ! unlink($path))) {
                throw new ContainmentException('backup_purge_failed');
            }
            $this->syncDirectory(dirname($path));
            $backup->update(['purged_at' => now()]);
            $op->update(['sealed_intent' => '', 'cache_requests' => null]);
            app(ContainmentAudit::class)->record($op, 'backup_purged');
        });
    }
}
