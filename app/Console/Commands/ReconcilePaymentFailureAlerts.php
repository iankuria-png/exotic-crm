<?php

namespace App\Console\Commands;

use App\Jobs\SendPaymentFailureAlertsJob;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Console\Command;

class ReconcilePaymentFailureAlerts extends Command
{
    protected $signature = 'crm:reconcile-payment-failure-alerts
        {--limit=200 : Maximum number of recent failed payments to inspect}
        {--window-minutes=1440 : Lookback window for recent failed payments}';

    protected $description = 'Backfill missing payment failure SMS alert coordinator jobs for recent live failed payments.';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $windowMinutes = max(5, (int) $this->option('window-minutes'));

        $payments = Payment::query()
            ->with(['platform:id'])
            ->where('status', 'failed')
            ->where('updated_at', '>=', now()->subMinutes($windowMinutes))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->reject(fn (Payment $payment): bool => $payment->isSandboxTest() || $payment->isClassifiedTest())
            ->values();

        $queued = 0;
        $skipped = 0;

        foreach ($payments as $payment) {
            $eventKey = $payment->paymentFailureAlertEventKey();

            $alreadyQueued = PaymentAttempt::query()
                ->where('payment_id', (int) $payment->id)
                ->where('attempt_type', 'payment_failure_alert_enqueue')
                ->get(['request_meta'])
                ->contains(fn (PaymentAttempt $attempt): bool => (string) data_get($attempt->request_meta, 'event_key') === $eventKey);

            if ($alreadyQueued) {
                $skipped++;
                continue;
            }

            SendPaymentFailureAlertsJob::dispatch((int) $payment->id, $eventKey, 'reconcile_payment_failure_alerts')
                ->onQueue('alerts');
            $queued++;
        }

        $this->info(sprintf(
            'Payment failure alert reconciliation complete: %d queued, %d skipped.',
            $queued,
            $skipped
        ));

        return self::SUCCESS;
    }
}
