<?php

namespace App\Billing\Support;

final class ProviderWebhookParseResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly string $providerKey,
        public readonly bool $accepted,
        public readonly ?string $eventType = null,
        public readonly ?string $eventId = null,
        public readonly ?string $providerReference = null,
        public readonly ?string $status = null,
        public readonly ?string $message = null,
        public readonly array $raw = []
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider_key' => $this->providerKey,
            'accepted' => $this->accepted,
            'event_type' => $this->eventType,
            'event_id' => $this->eventId,
            'provider_reference' => $this->providerReference,
            'status' => $this->status,
            'message' => $this->message,
            'raw' => $this->raw,
        ];
    }
}
