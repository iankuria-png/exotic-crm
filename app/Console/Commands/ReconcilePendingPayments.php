<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\BillingRoutingDecision;
use App\Services\BillingModeService;
use App\Services\HostedCheckoutService;
use App\Services\PaymentAttemptService;
use App\Services\PaymentCompletionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePendingPayments extends Command
{
    protected $signature = 'crm:reconcile-pending-payments
        {--limit=50 : Maximum number of stale payments to inspect}
        {--stale-minutes=60 : Minimum age in minutes since last update}
        {--delay-ms=1000 : Delay between provider API calls in milliseconds}
        {--include-sandbox : Include sandbox/test payments in this reconciliation run}';

    protected $description = 'Verify stale pending Paystack/Pesapal payments and reconcile missed callbacks.';

    public function __construct(
        private readonly BillingModeService $billingModeService,
        private readonly HostedCheckoutService $hostedCheckoutService,
        private readonly PaymentCompletionService $paymentCompletionService,
        private readonly PaymentAttemptService $paymentAttemptService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(1, min(50, (int) $this->option('limit')));
        $staleMinutes = max(1, (int) $this->option('stale-minutes'));
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $includeSandbox = (bool) $this->option('include-sandbox');

        $paymentsQuery = Payment::query()
            ->with([
                'platform',
                'client',
                'product',
                'routingDecisions' => fn ($query) => $query
                    ->where('immutable_until_terminal_state', true)
                    ->latest('id'),
            ])
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereIn('provider_key', ['paystack', 'pesapal'])
                    ->orWhereHas('routingDecisions', function ($decisionQuery) {
                        $decisionQuery->where('immutable_until_terminal_state', true)
                            ->whereIn('provider_type_key', ['paystack', 'pesapal']);
                    });
            })
            ->whereIn('purpose', ['wallet_topup', 'subscription'])
            ->where('updated_at', '<', now()->subMinutes($staleMinutes))
            ->orderBy('updated_at')
            ->limit($limit);

        $payments = $paymentsQuery->get()
            ->filter(fn (Payment $payment) => $this->shouldProcessPayment($payment, $includeSandbox))
            ->values();

        $totals = [
            'processed' => 0,
            'completed' => 0,
            'failed' => 0,
            'pending' => 0,
            'errors' => 0,
        ];

        foreach ($payments as $index => $payment) {
            $totals['processed']++;
            $status = 'failed';
            $message = null;

            try {
                $verification = $this->verifyPayment($payment);
                $status = (string) ($verification['status'] ?? 'failed');
                $message = (string) ($verification['message'] ?? '');
                $providerPayload = is_array($verification['data'] ?? null) ? $verification['data'] : [];

                if ($status === 'completed') {
                    $this->paymentCompletionService->complete($payment, $providerPayload, [
                        'transaction_reference' => $this->resolveTransactionReference($payment, $providerPayload),
                        'raw_payload' => [
                            'reconciliation' => [
                                'checked_at' => now()->toDateTimeString(),
                                'provider_status' => $status,
                            ],
                        ],
                    ]);
                    $totals['completed']++;
                } elseif ($status === 'failed') {
                    $this->markPaymentFailed($payment, $message ?: 'Provider reported a failed payment state.', $providerPayload);
                    $totals['failed']++;
                } else {
                    $totals['pending']++;
                }

                $this->recordAttempt($payment, $status, $message, $providerPayload, $staleMinutes);
                Log::info('Pending payment reconciliation result', [
                    'payment_id' => (int) $payment->id,
                    'provider' => $this->resolveProviderTypeKey($payment),
                    'purpose' => $payment->purpose,
                    'status' => $status,
                    'message' => $message,
                ]);
            } catch (Throwable $exception) {
                $totals['errors']++;
                $this->recordAttempt($payment, 'failed', $exception->getMessage(), [], $staleMinutes, 'verification_exception');
                Log::warning('Pending payment reconciliation failed', [
                    'payment_id' => (int) $payment->id,
                    'provider' => $this->resolveProviderTypeKey($payment),
                    'purpose' => $payment->purpose,
                    'error' => $exception->getMessage(),
                ]);
            }

            if ($delayMs > 0 && $index < ($payments->count() - 1)) {
                usleep($delayMs * 1000);
            }
        }

        $this->info(sprintf(
            'Pending payment reconciliation complete: %d processed, %d completed, %d failed, %d still pending, %d errors.',
            $totals['processed'],
            $totals['completed'],
            $totals['failed'],
            $totals['pending'],
            $totals['errors']
        ));

        return self::SUCCESS;
    }

    private function verifyPayment(Payment $payment): array
    {
        $payment->loadMissing('platform');
        $provider = $this->resolveProviderTypeKey($payment);
        $context = $this->billingModeService->providerContext(
            $payment->platform,
            $provider,
            requireEnabled: false,
            environmentOverride: $this->resolveEnvironment($payment)
        );

        return match ($provider) {
            'paystack' => $this->hostedCheckoutService->verifyPaystackTransaction(
                $payment,
                $context,
                (string) $payment->reference_number
            ),
            'pesapal' => $this->hostedCheckoutService->verifyPesapalTransaction(
                $payment,
                $context,
                $this->resolvePesapalTrackingId($payment)
            ),
            default => throw new \InvalidArgumentException('Unsupported provider for reconciliation.'),
        };
    }

    private function resolvePesapalTrackingId(Payment $payment): string
    {
        $trackingId = trim((string) (
            $payment->transaction_reference
            ?? data_get($payment->raw_payload, 'pesapal.order_tracking_id')
            ?? data_get($payment->payment_data, 'link_proxy.provider_reference')
            ?? ''
        ));

        if ($trackingId === '') {
            throw new \RuntimeException('Pesapal payment is missing a tracking id for reconciliation.');
        }

        return $trackingId;
    }

    private function resolveTransactionReference(Payment $payment, array $providerPayload = []): string
    {
        return (string) (
            data_get($providerPayload, 'reference')
            ?? data_get($providerPayload, 'order_tracking_id')
            ?? data_get($providerPayload, 'orderTrackingId')
            ?? $payment->transaction_reference
            ?? $payment->reference_number
        );
    }

    private function markPaymentFailed(Payment $payment, string $reason, array $providerPayload = []): Payment
    {
        if ($payment->wallet_transaction_id || (string) $payment->status === 'completed' || $payment->completed_at) {
            return $payment->fresh() ?? $payment;
        }

        $payment->forceFill([
            'status' => 'failed',
            'failure_reason' => mb_substr($reason, 0, 190),
            'raw_payload' => array_merge(is_array($payment->raw_payload) ? $payment->raw_payload : [], [
                'reconciliation_failure' => $providerPayload,
                'reconciliation_failed_at' => now()->toDateTimeString(),
            ]),
        ])->save();

        return $payment->fresh(['platform', 'client', 'product']);
    }

    private function recordAttempt(
        Payment $payment,
        string $status,
        ?string $message,
        array $providerPayload,
        int $staleMinutes,
        ?string $errorCode = null
    ): void {
        $this->paymentAttemptService->record($payment, 'reconciliation_check', $status, [
            'provider' => $payment->provider_key,
            'resolved_provider' => $this->resolveProviderTypeKey($payment),
            'error_code' => $errorCode,
            'error_message' => $message,
            'request_meta' => [
                'provider_environment' => $this->resolveEnvironment($payment),
                'reference_number' => $payment->reference_number,
                'transaction_reference' => $payment->transaction_reference,
                'stale_minutes' => $staleMinutes,
            ],
            'response_meta' => [
                'verification_status' => $status,
                'provider_payload' => $providerPayload,
            ],
        ]);
    }

    private function shouldProcessPayment(Payment $payment, bool $includeSandbox): bool
    {
        if ($includeSandbox) {
            return true;
        }

        if ((bool) data_get($payment->payment_data, 'test_mode', false)) {
            return false;
        }

        return $this->resolveEnvironment($payment) !== 'sandbox';
    }

    private function resolveProviderTypeKey(Payment $payment): string
    {
        return strtolower(trim((string) (
            $this->latestPinnedDecision($payment)?->provider_type_key
            ?? $payment->provider_key
            ?? ''
        )));
    }

    private function resolveEnvironment(Payment $payment): string
    {
        return strtolower(trim((string) (
            $this->latestPinnedDecision($payment)?->environment
            ?? $payment->provider_environment
            ?? 'production'
        )));
    }

    private function latestPinnedDecision(Payment $payment): ?BillingRoutingDecision
    {
        if ($payment->relationLoaded('routingDecisions')) {
            return $payment->routingDecisions->first();
        }

        return $payment->routingDecisions()
            ->where('immutable_until_terminal_state', true)
            ->latest('id')
            ->first();
    }
}
