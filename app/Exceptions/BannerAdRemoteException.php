<?php

namespace App\Exceptions;

use RuntimeException;

class BannerAdRemoteException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $responseStatus = 502,
        private readonly ?int $remoteStatus = null,
        private readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function responseStatus(): int
    {
        return $this->responseStatus;
    }

    public function remoteStatus(): ?int
    {
        return $this->remoteStatus;
    }

    public function context(): array
    {
        return $this->context;
    }
}
