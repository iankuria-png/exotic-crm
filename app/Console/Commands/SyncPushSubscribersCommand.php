<?php

namespace App\Console\Commands;

use App\Services\PushNotification\SubscriberSyncService;
use Illuminate\Console\Command;

class SyncPushSubscribersCommand extends Command
{
    protected $signature = 'crm:sync-push-subscribers';

    protected $description = 'Sync push subscriber counts from configured providers.';

    public function handle(SubscriberSyncService $subscriberSyncService): int
    {
        $summary = $subscriberSyncService->syncAllPlatforms();

        $this->info(sprintf('Push subscriber sync complete: %d platform snapshot(s) updated.', count($summary)));

        foreach ($summary as $row) {
            $this->line(sprintf(
                ' - platform_id=%d provider=%s active=%d total=%d',
                (int) ($row['platform_id'] ?? 0),
                (string) ($row['provider'] ?? 'unknown'),
                (int) ($row['active'] ?? 0),
                (int) ($row['total'] ?? 0)
            ));
        }

        return self::SUCCESS;
    }
}
