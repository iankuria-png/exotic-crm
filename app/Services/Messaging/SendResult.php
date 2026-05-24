<?php

namespace App\Services\Messaging;

final class SendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly string $status,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {
    }

    public static function sent(?string $providerMessageId, array $raw = []): self
    {
        return new self(true, 'sent', $providerMessageId, raw: $raw);
    }

    public static function failed(string $status, ?string $errorCode = null, ?string $errorMessage = null, array $raw = []): self
    {
        return new self(false, $status, null, $errorCode, $errorMessage, $raw);
    }
}
