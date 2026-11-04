<?php

namespace App\Services\Seo\Llm;

use RuntimeException;

/** Safe to log: never carries an upstream body, request URL or nested exception. */
class ProviderRequestException extends RuntimeException
{
    public function __construct(public readonly string $category, public readonly int $httpStatus = 0)
    {
        parent::__construct('AI request failed: '.$category.($httpStatus ? ' (HTTP '.$httpStatus.')' : ''));
    }

    public function affectsAvailability(): bool
    {
        return in_array($this->category, ['timeout', 'connection'], true)
            || $this->httpStatus === 429 || $this->httpStatus >= 500;
    }
}
