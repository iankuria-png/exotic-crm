<?php

namespace App\Services\AutoOptimize;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Resolves the automation system user by stable email key.
 * Fails closed: throws if missing, so apply never writes an audit row with a guessed id.
 */
class SystemActorResolver
{
    private const EMAIL = 'automation+auto-optimize@system.local';
    private const CACHE_KEY = 'auto_optimize_system_actor_id';
    private const CACHE_TTL = 3600; // 1 hour

    public static function id(): int
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return (int) $cached;
        }

        $user = User::query()->where('email', self::EMAIL)->first();

        if ($user === null) {
            throw new RuntimeException(
                'Auto Optimize system actor not found. Run `php artisan migrate` to seed the system user.'
            );
        }

        Cache::put(self::CACHE_KEY, (int) $user->id, self::CACHE_TTL);

        return (int) $user->id;
    }

    /** Flush cache, e.g. after re-seeding in tests. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
