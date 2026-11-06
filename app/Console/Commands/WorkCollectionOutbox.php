<?php

namespace App\Console\Commands;

use App\Services\Collections\CollectionOutboxWorker;
use Illuminate\Console\Command;

class WorkCollectionOutbox extends Command
{
    protected $signature = 'collections:work {--limit=100} {--loop} {--poll=2} {--max-runtime=0}';

    protected $description = 'Process durable collection capture, decision and existing fulfillment work';

    public function handle(CollectionOutboxWorker $worker): int
    {
        $runtime = max(0, (int) $this->option('max-runtime'));
        $deadline = $runtime > 0 ? hrtime(true) + $runtime * 1_000_000_000 : null;
        $stop = false;
        if (function_exists('pcntl_signal')) {
            $this->trap([SIGINT, SIGTERM], function () use (&$stop) {
                $stop = true;
            });
        }
        do {
            if ($deadline !== null && hrtime(true) >= $deadline) {
                break;
            }
            $result = $worker->run(max(1, (int) $this->option('limit')), $deadline);
            if ($result['done'] || $result['held'] || ! $this->option('loop')) {
                $this->info('Processed '.$result['done'].'; held '.$result['held'].'.');
            }
            if ($this->option('loop') && ! $stop) {
                $pause = max(1, min(10, (int) $this->option('poll')));
                if ($deadline !== null) {
                    $pause = min($pause, max(0, (int) ceil(($deadline - hrtime(true)) / 1_000_000_000)));
                }
                if ($pause > 0) {
                    sleep($pause);
                }
            }
        } while ($this->option('loop') && ! $stop);

        return self::SUCCESS;
    }
}
