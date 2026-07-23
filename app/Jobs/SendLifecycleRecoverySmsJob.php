<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Services\LifecycleSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Client-facing failed-payment recovery SMS. A NEW, parallel consumer of the
 * same payment.failed trigger that feeds the staff failure alerts — staff keep
 * their alert, the client gets a "complete your payment" SMS with a fresh
 * tokenized link. All gating (per-market flags, manual/test exclusions, state
 * re-check, dedup) lives in LifecycleSmsService.
 */
class SendLifecycleRecoverySmsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $paymentId,
        public readonly string $triggerSource = 'payment_model_saved',
    ) {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return 'lifecycle-recovery:' . $this->paymentId;
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(LifecycleSmsService $lifecycleSmsService): void
    {
        $payment = Payment::query()
            ->with(['client.platform', 'deal'])
            ->find($this->paymentId);

        if (!$payment || !$payment->client) {
            return;
        }

        $result = $lifecycleSmsService->send(LifecycleSmsService::FLOW_RECOVERY, $payment->client, [
            'payment' => $payment,
            'source' => 'automated',
        ]);

        Log::info('Lifecycle recovery SMS job finished', [
            'payment_id' => $this->paymentId,
            'trigger_source' => $this->triggerSource,
            'status' => $result['status'] ?? null,
            'skip_reason' => $result['skip_reason'] ?? null,
        ]);
    }
}
