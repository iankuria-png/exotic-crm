<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\NotificationService;
use App\Services\PaymentAttemptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SendPaymentFailureAlertRecipientJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    /**
     * @param array{id:int,name:string,role:string,phone:?string} $recipient
     */
    public function __construct(
        public readonly int $paymentId,
        public readonly string $eventKey,
        public readonly array $recipient,
        public readonly string $triggerSource = 'payment_failure_alert_enqueue',
    ) {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return sprintf(
            'payment-failure-alert-recipient:%s:%d',
            $this->eventKey,
            (int) ($this->recipient['id'] ?? 0)
        );
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(
        NotificationService $notificationService,
        PaymentAttemptService $paymentAttemptService
    ): void {
        $payment = Payment::query()
            ->with([
                'client:id,name',
                'product:id,name,display_name',
                'platform:id,phone_prefix',
            ])
            ->find($this->paymentId);

        if (!$payment) {
            return;
        }

        if ($this->hasSuccessfulAttempt($payment)) {
            return;
        }

        if ((string) $payment->status !== 'failed') {
            $this->recordAttempt($payment, $paymentAttemptService, 'skipped', 'payment_not_failed');
            return;
        }

        if ($payment->isSandboxTest() || $payment->isClassifiedTest()) {
            $this->recordAttempt($payment, $paymentAttemptService, 'skipped', 'test_payment');
            return;
        }

        $phone = trim((string) ($this->recipient['phone'] ?? ''));
        if ($phone === '') {
            $this->recordAttempt($payment, $paymentAttemptService, 'skipped', 'missing_phone');
            return;
        }

        $result = $notificationService->sendSms($phone, $this->buildMessage($payment), [
            'platform_id' => (int) $payment->platform_id,
            'phone_prefix' => $payment->platform?->phone_prefix ?: null,
            'payment_id' => (int) $payment->id,
            'alert_type' => 'payment_failure',
        ]);

        $status = ($result['success'] ?? false) ? 'sent' : 'failed';

        $this->recordAttempt($payment, $paymentAttemptService, $status, $status === 'failed' ? 'provider_failed' : null, [
            'provider_result' => $result,
        ]);

        if ($status === 'failed') {
            throw new RuntimeException((string) ($result['message'] ?? 'Payment failure SMS dispatch failed.'));
        }
    }

    private function hasSuccessfulAttempt(Payment $payment): bool
    {
        return PaymentAttempt::query()
            ->where('payment_id', (int) $payment->id)
            ->where('attempt_type', 'payment_failure_alert_sms')
            ->where('status', 'sent')
            ->get(['request_meta'])
            ->contains(function (PaymentAttempt $attempt): bool {
                return (string) data_get($attempt->request_meta, 'event_key') === $this->eventKey
                    && (int) data_get($attempt->request_meta, 'user_id') === (int) ($this->recipient['id'] ?? 0);
            });
    }

    private function recordAttempt(
        Payment $payment,
        PaymentAttemptService $paymentAttemptService,
        string $status,
        ?string $errorCode = null,
        array $extraResponseMeta = []
    ): void {
        $paymentAttemptService->record($payment, 'payment_failure_alert_sms', $status, [
            'provider' => $payment->provider_key,
            'error_code' => $errorCode,
            'error_message' => $errorCode ? str_replace('_', ' ', $errorCode) : null,
            'request_meta' => array_filter([
                'event_key' => $this->eventKey,
                'trigger_source' => $this->triggerSource,
                'user_id' => (int) ($this->recipient['id'] ?? 0),
                'user_name' => $this->recipient['name'] ?? null,
                'user_role' => $this->recipient['role'] ?? null,
                'phone' => $this->recipient['phone'] ?? null,
                'platform_id' => (int) $payment->platform_id,
                'phone_prefix' => $payment->platform?->phone_prefix ?: null,
            ], static fn ($value) => $value !== null && $value !== ''),
            'response_meta' => array_filter(array_merge([
                'status_changed_at' => data_get($payment->raw_payload, 'payment_failure_alert.status_changed_at'),
            ], $extraResponseMeta), static fn ($value) => $value !== null),
        ]);
    }

    private function buildMessage(Payment $payment): string
    {
        $clientName = $payment->client?->name ?? 'Unknown';
        $currency = (string) ($payment->currency ?? 'KES');
        $amount = number_format((float) ($payment->amount ?? 0), 2);
        $product = $payment->product
            ? ($payment->product->display_name ?: $payment->product->name)
            : 'Unknown product';
        $reason = mb_substr((string) ($payment->failure_reason ?? 'Unknown'), 0, 60);
        $reference = mb_substr((string) ($payment->reference_number ?? $payment->transaction_reference ?? '-'), 0, 20);

        return "Payment alert: {$clientName} {$currency} {$amount} failed ({$product}). Reason: {$reason}. Ref: {$reference}. Login to review.";
    }
}
