<?php

namespace App\Jobs;

use App\Services\Ai\BriefingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunAiBriefingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly string $audience,
        public readonly ?int $triggeredBy = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(BriefingService $briefings): void
    {
        $briefings->run($this->audience, false, null, $this->triggeredBy);
    }
}
