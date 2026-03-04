<?php

namespace App\Exceptions;

use RuntimeException;

class PushCampaignActivationBlockedException extends RuntimeException
{
    /**
     * @param array<string, mixed> $readiness
     */
    public function __construct(
        private readonly array $readiness,
        ?string $message = null
    ) {
        parent::__construct($message ?: (string) ($readiness['message'] ?? 'Push campaign activation blocked.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function readiness(): array
    {
        return $this->readiness;
    }
}
