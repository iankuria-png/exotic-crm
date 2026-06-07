<?php

namespace App\Services;

/**
 * Injectable factory for platform-scoped WpSyncService instances.
 *
 * WpSyncService::__construct requires a Platform, so it must NOT be
 * constructor-injected directly (the container would build it with an empty
 * Platform → null baseUrl → every WP call misrouted). Inject this factory
 * instead and resolve per platform at call time.
 */
class WpSyncFactory
{
    public function forPlatform(int $platformId): WpSyncService
    {
        return WpSyncService::forPlatform($platformId);
    }
}
