<?php

namespace App\Jobs\DbContainment;

use App\Services\DbContainment\ContainmentExecutor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ContainmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $operationId)
    {
        $this->onConnection('database_long');
    }

    public function handle(ContainmentExecutor $executor): void
    {
        $executor->execute($this->operationId);
    }
}
