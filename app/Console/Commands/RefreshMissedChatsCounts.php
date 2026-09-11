<?php

namespace App\Console\Commands;

use App\Services\MissedChatsCountService;
use App\Services\SupportBoardService;
use Illuminate\Console\Command;

class RefreshMissedChatsCounts extends Command
{
    protected $signature = 'crm:refresh-missed-chats';

    protected $description = 'Recompute the dashboard missed-chats figure for every configured market into cache.';

    public function handle(MissedChatsCountService $service): int
    {
        if (! SupportBoardService::isEnabled()) {
            $this->warn('Support Board is switched off. Nothing to refresh.');

            return self::SUCCESS;
        }

        $result = $service->refreshAll();

        $this->info(sprintf(
            'Missed chats refreshed: %d market(s), %d failed, %d skipped.',
            $result['refreshed'],
            $result['failed'],
            $result['skipped']
        ));

        // A failed market keeps its previous cached figure, so a partial refresh
        // is a degraded success rather than an error worth alerting on.
        return self::SUCCESS;
    }
}
