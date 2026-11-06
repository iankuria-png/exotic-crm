<?php

namespace App\Console\Commands;

use App\Services\Collections\CollectionOutboxWorker;
use Illuminate\Console\Command;

class WorkCollectionOutbox extends Command
{
    protected $signature = 'collections:work {--limit=100} {--loop} {--poll=2}';

    protected $description = 'Process durable collection capture, decision and existing fulfillment work';

    public function handle(CollectionOutboxWorker $worker): int
    {
        $stop = false;
        if (function_exists('pcntl_signal')) {
            $this->trap([SIGINT, SIGTERM], function () use (&$stop) {
                $stop = true;
            });
        }
        do {
            $result = $worker->run(max(1, (int) $this->option('limit')));
            if ($result['done'] || $result['held'] || ! $this->option('loop')) {
                $this->info('Processed '.$result['done'].'; held '.$result['held'].'.');
            }
            if ($this->option('loop') && ! $stop) {
                sleep(max(1, min(10, (int) $this->option('poll'))));
            }
        } while ($this->option('loop') && ! $stop);

        return self::SUCCESS;
    }
}
