<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\PaymentFulfillmentRecoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ResumePaidPaymentService implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public int $timeout = 180;

    public int $uniqueFor = 240;

    public function __construct(public int $paymentId)
    {
        $this->afterCommit();
    }

    public function uniqueId(): string
    {
        return (string) $this->paymentId;
    }

    public function handle(PaymentFulfillmentRecoveryService $service): void
    {
        if ($p = Payment::find($this->paymentId)) {
            $service->resume($p);
        }
    }
}
