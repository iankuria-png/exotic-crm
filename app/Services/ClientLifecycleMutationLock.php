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
    /** The real shared leases, with one bounded budget for multi-profile preparation. */
    public function runBounded(array $clientIds, Closure $callback): mixed
    {
        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));
        sort($clientIds, SORT_NUMERIC);
        $held = [];
        $deadline = hrtime(true) / 1e9 + 90;
        $ttl = max(30, (int) config('client_lifecycle.lock_ttl_seconds', 600));
        $remaining = function (bool $commit = false) use (&$held, &$deadline): float {
            $seconds = $deadline - hrtime(true) / 1e9 - ($commit ? 0 : 10);
            foreach ($held as $lock) {
                if (! method_exists($lock, 'isOwnedByCurrentProcess') || ! $lock->isOwnedByCurrentProcess()) {
                    throw ClientLifecycleMutationException::busy();
                }
            }
            if ($seconds <= 0) {
                throw ClientLifecycleMutationException::busy();
            }

            return $seconds;
        };
        try {
            foreach ($clientIds as $clientId) {
                $lock = $this->lock($this->key($clientId));
                $wait = min(5, (int) floor($remaining()));
                $started = hrtime(true) / 1e9;
                if (! $lock->block(max(0, $wait))) {
                    throw ClientLifecycleMutationException::busy();
                }
                $held[] = $lock;
                $deadline = min($deadline, $started + $ttl - 10);
                app(\App\Services\Collections\CollectionWriterGuard::class)->assertClient($clientId);
            }
            $remaining();

            return $callback($remaining);
        } finally {
            foreach (array_reverse($held) as $lock) {
                $lock->release(); // Cache drivers release only their own token.
            }
        }
    }

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
            app(\App\Services\Collections\CollectionWriterGuard::class)->assertClient($clientId);
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
