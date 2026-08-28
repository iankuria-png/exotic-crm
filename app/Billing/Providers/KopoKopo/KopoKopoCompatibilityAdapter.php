<?php

namespace App\Billing\Providers\KopoKopo;

use App\Services\KopokopoService;

class KopoKopoCompatibilityAdapter
{
    public function __construct(
        private readonly KopokopoService $kopokopoService
    ) {
    }

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
