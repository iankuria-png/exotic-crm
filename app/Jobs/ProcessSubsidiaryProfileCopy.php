<?php

namespace App\Jobs;

use App\Services\SubsidiaryProfileCopyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One bounded slice of a profile copy. The run's database lease, not the queue, prevents overlap. */
class ProcessSubsidiaryProfileCopy implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 80;

    public function __construct(public string $runId)
    {
        $this->onQueue('default');
    }

    public function handle(SubsidiaryProfileCopyService $copies): void
    {
        $copies->process($this->runId);
    }
}
