<?php

namespace App\Jobs;

use App\Services\ReportingFxRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Staff-triggered refresh from the Reporting Currency card. Runs on the fast lane:
 * at most six paced provider calls, well inside the lane's five-minute budget.
 */
class RefreshReportingFxRatesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public readonly ?int $userId = null)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'reporting-fx-refresh';
    }

    public function handle(ReportingFxRefreshService $refreshService): void
    {
        $refreshService->refresh(null, false, 'manual', $this->userId);
    }
}
