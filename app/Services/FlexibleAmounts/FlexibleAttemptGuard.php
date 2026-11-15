<?php

namespace App\Services\FlexibleAmounts;

use App\Exceptions\ClientLifecycleMutationException;
use App\Models\FlexibleCheckoutAttempt;
use App\Models\Payment;
use Illuminate\Support\Facades\Schema;

class FlexibleAttemptGuard
{
    private ?int $ownedPayment = null;

    public function owned(Payment $payment, \Closure $callback): mixed
    {
        $previous = $this->ownedPayment;
        $this->ownedPayment = $payment->id;
        try {
            return $callback();
        } finally {
            $this->ownedPayment = $previous;
        }
    }

    public function assertClient(int $client): void
    {
        if (! Schema::hasTable('flexible_checkout_attempts')) {
            return;
        }
        $q = FlexibleCheckoutAttempt::where('blocking_client_id', $client)->whereHas('payment', fn ($p) => $p->whereNotIn('status', ['failed', 'cancelled', 'canceled']));
        if ($this->ownedPayment) {
            $q->where('payment_id', '!=', $this->ownedPayment);
        }
        if ($q->exists()) {
            throw ClientLifecycleMutationException::busy();
        }
    }

    public function releaseTerminal(int $client): void
    {
        FlexibleCheckoutAttempt::where('blocking_client_id', $client)->whereHas('payment', fn ($p) => $p->whereIn('status', ['failed', 'cancelled', 'canceled']))->update(['blocking_client_id' => null, 'state' => 'failed']);
    }

    public function finish(Payment $payment): void
    {
        FlexibleCheckoutAttempt::where('payment_id', $payment->id)->update(['blocking_client_id' => null, 'state' => 'finished']);
    }
}
