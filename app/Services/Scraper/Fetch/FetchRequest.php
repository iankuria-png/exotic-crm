<?php

namespace App\Services\Scraper\Fetch;

final class FetchRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<int, array{name: string, value: string, domain?: string}> $cookies
     */
    public function __construct(
        public readonly string $url,
        public readonly array $headers = [],
        public readonly array $cookies = [],
        public readonly int $timeoutSeconds = 25,
        public readonly ?string $waitForSelector = null,
        public readonly int $scrollSteps = 0,
        public readonly ?string $userAgent = null,
        public readonly ?int $sourceId = null,
    ) {
    }

    public function withUrl(string $url): self
    {
        return new self($url, $this->headers, $this->cookies, $this->timeoutSeconds, $this->waitForSelector, $this->scrollSteps, $this->userAgent, $this->sourceId);
    }
}
