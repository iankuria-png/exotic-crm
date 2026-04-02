<?php

namespace App\Jobs;

use App\Models\Platform;
use App\Services\WalletSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncPlatformWalletClientsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600; // 10 minutes

    public function __construct(public Platform $platform)
    {
    }

    public function handle(WalletSyncService $walletSyncService): void
    {
        Log::info('SyncPlatformWalletClientsJob starting', ['platform_id' => (int) $this->platform->id]);

        $results = $walletSyncService->syncAllPlatformClients($this->platform);

        Log::info('SyncPlatformWalletClientsJob finished', [
            'platform_id' => (int) $this->platform->id,
            'results' => $results,
        ]);
    }
}
