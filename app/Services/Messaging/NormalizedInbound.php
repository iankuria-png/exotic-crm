<?php

namespace App\Services\Messaging;

final class NormalizedInbound
{
    public function __construct(
        public readonly string $phoneE164,
        public readonly string $body,
        public readonly string $providerMessageId,
        public readonly ?string $platformMessageId = null,
        public readonly array $raw = [],
    ) {
    }
}
