<?php

namespace App\Services;

use App\Exceptions\ClientLifecycleMutationException;
use App\Models\Client;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Serializes WordPress-first activation and destructive lifecycle mutations.
 *
 * When called inside an outer transaction the lock is intentionally held until
 * commit. A rollback has no after-commit hook, so the lease remains fail-closed
 * until its bounded TTL expires instead of exposing uncommitted CRM state.
 */
class ClientLifecycleMutationLock
{
    public function run(Client|int $client, Closure $callback): mixed
    {
        $clientId = $client instanceof Client ? (int) $client->id : (int) $client;
        $key = $this->key($clientId);
        $lock = $this->lock($key);

        try {
            if (! $lock->block(max(0, (int) config('client_lifecycle.lock_wait_seconds', 5)))) {
                throw ClientLifecycleMutationException::busy();
            }
        } catch (ClientLifecycleMutationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ClientLifecycleMutationException::busy();
        }

        try {
            $result = $callback();
        } catch (\Throwable $exception) {
            $this->releaseAfterOutcome($lock, $key, false);
            throw $exception;
        }

        $this->releaseAfterOutcome($lock, $key, true);

        return $result;
    }

    public function isBusy(Client|int $client): bool
    {
        $clientId = $client instanceof Client ? (int) $client->id : (int) $client;
        try {
            $lock = $this->lock($this->key($clientId));
        } catch (\Throwable) {
            return true;
        }

        if (! $lock->get()) {
            return true;
        }

        $lock->release();

        return false;
    }

    private function key(int $clientId): string
    {
        return "client-lifecycle-mutation:{$clientId}";
    }

    private function lock(string $key): Lock
    {
        $store = trim((string) config('client_lifecycle.lock_store', ''));
        if (app()->environment('production')) {
            $driver = $store !== '' ? (string) config("cache.stores.{$store}.driver", '') : '';
            if ($store === '' || in_array($driver, ['', 'array', 'file'], true)) {
                throw new \RuntimeException('CLIENT_LIFECYCLE_LOCK_STORE must name a shared atomic cache store before lifecycle mutations can run.');
            }
        }
        $repository = $store !== '' ? Cache::store($store) : Cache::store();

        return $repository->lock(
            $key,
            max(30, (int) config('client_lifecycle.lock_ttl_seconds', 600)),
        );
    }

    private function releaseAfterOutcome(Lock $lock, string $key, bool $succeeded): void
    {
        if (DB::transactionLevel() <= 0
            || (app()->environment('testing') && ! config('client_lifecycle.hold_during_test_transactions', false))) {
            $lock->release();

            return;
        }

        if (! $succeeded) {
            return;
        }

        $owner = $lock->owner();
        $store = trim((string) config('client_lifecycle.lock_store', ''));
        DB::afterCommit(static function () use ($key, $owner, $store): void {
            $repository = $store !== '' ? Cache::store($store) : Cache::store();
            $repository->restoreLock($key, $owner)->release();
        });
    }
}
