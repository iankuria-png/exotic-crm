<?php

namespace App\Billing\Support;

final class ProviderStatusResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $message = null,
        public readonly bool $terminal = false,
        public readonly array $raw = []
    ) {
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'completed';
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
            'message' => $this->message,
            'terminal' => $this->terminal,
            'raw' => $this->raw,
        ];
    }
}
