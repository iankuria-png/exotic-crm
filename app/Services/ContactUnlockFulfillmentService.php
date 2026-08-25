<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\VisitorContactUnlock;
use Illuminate\Support\Facades\DB;

class ContactUnlockFulfillmentService
{
    public function __construct(
        private readonly ContactUnlockUpgradeQuoteService $upgradeQuoteService
    ) {}

    public function fulfill(Payment $payment, array $providerPayload = []): VisitorContactUnlock
    {
        return DB::transaction(function () use ($payment, $providerPayload): VisitorContactUnlock {
            $unlock = VisitorContactUnlock::query()
                ->where('payment_id', (int) $payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($unlock->isActive()) {
                return $unlock->fresh(['payment', 'client', 'pricingRule']);
            }

            $durationDays = max(1, (int) ($unlock->pricingRule?->duration_days ?? 1));
            $metadata = is_array($unlock->metadata_json) ? $unlock->metadata_json : [];
            $metadata['fulfilled_payment_id'] = (int) $payment->id;
            $metadata['fulfilled_at'] = now()->toIso8601String();
            $metadata['provider_reference'] = $payment->transaction_reference ?: $payment->reference_number;
            $metadata['provider_payload_summary'] = [
                'status' => data_get($providerPayload, 'status'),
                'reference' => data_get($providerPayload, 'reference') ?? data_get($providerPayload, 'depositId'),
            ];

            $unlock->forceFill([
                'status' => VisitorContactUnlock::STATUS_ACTIVE,
                'starts_at' => now(),
                'expires_at' => now()->addDays($durationDays),
                'metadata_json' => $metadata,
            ])->save();

            $this->upgradeQuoteService->applyReservedCredits($unlock);

            return $unlock->fresh(['payment', 'client', 'pricingRule']);
        });
    }
}
