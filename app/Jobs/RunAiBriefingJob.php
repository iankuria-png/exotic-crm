<?php

namespace App\Jobs;

use App\Jobs\Concerns\Sheddable;
use App\Services\Ai\BriefingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunAiBriefingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, Sheddable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly string $audience,
        public readonly ?int $triggeredBy = null,
    ) {
        $this->onQueue('default');
    }

    public function shedCapability(): string
    {
        return 'ai_briefings';
    }

    public function handle(BriefingService $briefings): void
    {
        // Stand down while the platform is shedding load. The job is
        // released with a delay rather than failed, so the work is
        // deferred and the worker process is freed immediately.
        if ($this->shedIfDegraded()) {
            return;
        }

        $briefings->run($this->audience, false, null, $this->triggeredBy);
    }
}
