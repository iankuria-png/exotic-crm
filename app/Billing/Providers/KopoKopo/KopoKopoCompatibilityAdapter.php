<?php

namespace App\Billing\Providers\KopoKopo;

use App\Services\KopokopoService;

class KopoKopoCompatibilityAdapter
{
    public function __construct(
        private readonly KopokopoService $kopokopoService
    ) {}

    public function initiateStkPush(
        string $phone,
        float $amount,
        string $callbackUrl,
        array $metadata = [],
        array $configOverride = []
    ): array {
        return $this->service()->initiateStkPush(
            $phone,
            $amount,
            $callbackUrl,
            $metadata,
            $configOverride
        );
    }

    public function verify(\App\Models\Payment $payment, array $context, string $location): array
    {
        $payload = $this->service()->paymentStatus($location, $context['provider_direct_config'] ?? []);
        // A provider response must refer to the same initiated payment.
        if ((string) data_get($payload, 'metadata.payment_id') !== (string) $payment->id) {
            throw new \RuntimeException('KopoKopo status metadata does not match this payment.');
        }
        $statuses = [strtolower(trim((string) ($payload['status'] ?? ''))), strtolower(trim((string) ($payload['resourceStatus'] ?? '')))];
        $status = 'pending';
        if (array_intersect($statuses, ['failed', 'cancelled', 'canceled', 'rejected', 'declined', 'expired', 'reversed'])) {
            $status = 'failed';
        } elseif (array_intersect($statuses, ['success', 'received', 'completed'])) {
            $status = 'completed';
        }

        return ['status' => $status, 'message' => 'KopoKopo reports '.$status.'.', 'data' => $payload];
    }

    public function handleWebhook(string $rawBody, string $signature): array
    {
        return $this->service()->handleWebhook($rawBody, $signature);
    }

    private function service(): KopokopoService
    {
        $resolved = app(KopokopoService::class);

        if ($resolved instanceof KopokopoService && get_class($resolved) !== KopokopoService::class) {
            return $resolved;
        }

        return $this->kopokopoService;
    }
}
