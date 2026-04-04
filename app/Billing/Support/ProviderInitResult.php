<?php

namespace App\Billing\Support;

final class ProviderInitResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $externalReference = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $message = null,
        public readonly array $raw = []
    ) {
    }

    public function isSuccessful(): bool
    {
        return in_array($this->status, ['initiated', 'pending_action', 'pending_confirmation'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'status' => $this->status,
            'provider_reference' => $this->providerReference,
            'external_reference' => $this->externalReference,
            'redirect_url' => $this->redirectUrl,
            'message' => $this->message,
            'raw' => $this->raw,
        ];
    }
}
