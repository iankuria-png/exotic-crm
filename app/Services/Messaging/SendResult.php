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
        public readonly ?int $senderId = null,
        public readonly ?string $attemptUuid = null,
        public readonly ?int $costMicros = null,
        public readonly array $raw = [],
    ) {
    }

    public static function sent(?string $providerMessageId, array $raw = [], ?int $senderId = null, ?string $attemptUuid = null, ?int $costMicros = null): self
    {
        return new self(true, 'sent', $providerMessageId, senderId: $senderId, attemptUuid: $attemptUuid, costMicros: $costMicros, raw: $raw);
    }

    public static function failed(string $status, ?string $errorCode = null, ?string $errorMessage = null, array $raw = [], ?int $senderId = null, ?string $attemptUuid = null): self
    {
        return new self(false, $status, null, $errorCode, $errorMessage, senderId: $senderId, attemptUuid: $attemptUuid, raw: $raw);
    }
}
