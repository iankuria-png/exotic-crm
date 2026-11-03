<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileTourOrder implements ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public $tries = 3;

    public $timeout = 180;

    public function __construct(public int $orderId) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(\App\Services\Tours\DistributionService $service): void
    {
        $service->reconcile($this->orderId);
    }
}
