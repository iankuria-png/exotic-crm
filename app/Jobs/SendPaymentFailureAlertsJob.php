<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Services\NotificationService;
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

    public function __construct(public readonly int $paymentId)
    {
    }

    public function uniqueId(): string
    {
        return 'payment-failure-alert-' . $this->paymentId;
    }

    public function backoff(): array
    {
        return [30, 60, 120];
    }

    public function handle(
        NotificationService $notificationService,
        MarketAuthorizationService $marketAuthorizationService
    ): void {
        $payment = Payment::query()
            ->with([
                'client:id,name',
                'product:id,name,display_name',
                'platform:id,phone_prefix',
            ])
            ->find($this->paymentId);

        if (!$payment || (string) $payment->status !== 'failed') {
            return;
        }

        if ($payment->isSandboxTest() || $payment->isClassifiedTest()) {
            Log::info('SendPaymentFailureAlertsJob: skipping test payment.', [
                'payment_id' => $this->paymentId,
            ]);
            return;
        }

        $platformId = (int) $payment->platform_id;
        if ($platformId <= 0) {
            return;
        }

        $recipients = $this->resolveRecipients($marketAuthorizationService, $platformId);

        if ($recipients->isEmpty()) {
            Log::info('SendPaymentFailureAlertsJob: no eligible recipients.', [
                'payment_id' => $this->paymentId,
                'platform_id' => $platformId,
            ]);
            return;
        }

        $message = $this->buildMessage($payment);
        $phonePrefix = $payment->platform?->phone_prefix ?: null;

        foreach ($recipients as $user) {
            if (!filled($user->phone)) {
                Log::info('SendPaymentFailureAlertsJob: skipping user with no phone.', [
                    'user_id' => $user->id,
                    'payment_id' => $this->paymentId,
                ]);
                continue;
            }

            $result = $notificationService->sendSms($user->phone, $message, [
                'platform_id' => $platformId,
                'phone_prefix' => $phonePrefix,
                'payment_id' => $payment->id,
                'alert_type' => 'payment_failure',
            ]);

            Log::info('SendPaymentFailureAlertsJob: SMS dispatched.', [
                'payment_id' => $this->paymentId,
                'user_id' => $user->id,
                'success' => $result['success'] ?? false,
                'status' => $result['status'] ?? 'unknown',
            ]);
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
            ->get(['id', 'role', 'status', 'phone', 'assigned_market_ids', 'notification_prefs'])
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
