<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Services\PaymentAttemptService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPaymentFailureAlertsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $paymentId,
        public readonly string $eventKey,
        public readonly string $triggerSource = 'payment_model_saved',
    ) {
        $this->onQueue('alerts');
    }

    public function uniqueId(): string
    {
        return 'payment-failure-alert:' . $this->eventKey;
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(
        PaymentAttemptService $paymentAttemptService,
        MarketAuthorizationService $marketAuthorizationService
    ): void {
        $payment = Payment::query()
            ->with([
                'client:id,name',
                'platform:id,name,phone_prefix',
            ])
            ->find($this->paymentId);

        if (!$payment) {
            return;
        }

        if ((string) $payment->status !== 'failed') {
            $this->recordEnqueueAttempt($payment, $paymentAttemptService, 'skipped', 'payment_not_failed');
            return;
        }

        if ($payment->isSandboxTest() || $payment->isClassifiedTest()) {
            $this->recordEnqueueAttempt($payment, $paymentAttemptService, 'skipped', 'test_payment');
            Log::info('SendPaymentFailureAlertsJob: skipping test payment.', [
                'payment_id' => $this->paymentId,
                'event_key' => $this->eventKey,
            ]);
            return;
        }

        $platformId = (int) $payment->platform_id;
        if ($platformId <= 0) {
            $this->recordEnqueueAttempt($payment, $paymentAttemptService, 'skipped', 'missing_platform');
            return;
        }

        $recipients = $this->resolveRecipients($marketAuthorizationService, $platformId);
        $recipientSnapshot = $recipients
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'role' => (string) $user->role,
                'phone' => $user->phone ? (string) $user->phone : null,
            ])
            ->values()
            ->all();

        if (empty($recipientSnapshot)) {
            $this->recordEnqueueAttempt($payment, $paymentAttemptService, 'skipped', 'no_recipients', [
                'recipient_snapshot' => [],
                'recipient_count' => 0,
            ]);
            Log::info('SendPaymentFailureAlertsJob: no eligible recipients.', [
                'payment_id' => $this->paymentId,
                'platform_id' => $platformId,
                'event_key' => $this->eventKey,
            ]);
            return;
        }

        $this->recordEnqueueAttempt($payment, $paymentAttemptService, 'queued', null, [
            'recipient_snapshot' => $recipientSnapshot,
            'recipient_count' => count($recipientSnapshot),
        ]);

        foreach ($recipientSnapshot as $recipient) {
            SendPaymentFailureAlertRecipientJob::dispatch(
                (int) $payment->id,
                $this->eventKey,
                $recipient,
                $this->triggerSource
            )->onQueue('alerts');
        }
    }

    private function resolveRecipients(
        MarketAuthorizationService $marketAuthorizationService,
        int $platformId
    ): EloquentCollection {
        return User::query()
            ->whereIn('role', ['admin', 'sub_admin', 'sales'])
            ->where('status', 'active')
            ->with('platforms:id')
            ->get(['id', 'name', 'role', 'status', 'phone', 'assigned_market_ids', 'notification_prefs'])
            ->filter(function (User $user) use ($marketAuthorizationService, $platformId): bool {
                if (!$marketAuthorizationService->userCanAccessPlatform($user, $platformId)) {
                    return false;
                }

                if (!$user->paymentFailureSmsEnabled()) {
                    return false;
                }

                if (in_array($user->role, ['admin', 'sub_admin'], true)) {
                    $scopedMarkets = $user->paymentFailureSmsMarketIds();
                    if ($scopedMarkets !== null && !in_array($platformId, $scopedMarkets, true)) {
                        return false;
                    }
                }

                return true;
            })
            ->values();
    }

    private function recordEnqueueAttempt(
        Payment $payment,
        PaymentAttemptService $paymentAttemptService,
        string $status,
        ?string $skipReason = null,
        array $extra = []
    ): void {
        $paymentAttemptService->record($payment, 'payment_failure_alert_enqueue', $status, [
            'provider' => $payment->provider_key,
            'error_code' => $skipReason,
            'error_message' => $skipReason ? str_replace('_', ' ', $skipReason) : null,
            'request_meta' => array_filter([
                'event_key' => $this->eventKey,
                'trigger_source' => $this->triggerSource,
                'platform_id' => (int) $payment->platform_id,
                'reference_number' => $payment->reference_number,
                'transaction_reference' => $payment->transaction_reference,
                'status_changed_at' => data_get($payment->raw_payload, 'payment_failure_alert.status_changed_at'),
            ]),
            'response_meta' => array_filter(array_merge([
                'skip_reason' => $skipReason,
            ], $extra), static fn ($value) => $value !== null),
        ]);
    }
}
