<?php

namespace App\Services\Scraper\Fetch;

final class FetchResult
{
    /**
     * @param array<string, string> $headers lower-cased header name => joined value
     * @param array{type: string, detail?: string}|null $blocked driver-observed block hint
     */
    public function __construct(
        public readonly string $requestedUrl,
        public readonly string $finalUrl,
        public readonly int $status,
        public readonly array $headers,
        public readonly string $html,
        public readonly int $bytes,
        public readonly int $elapsedMs,
        public readonly string $driver,
        public readonly ?string $error = null,
        public readonly ?array $blocked = null,
        public readonly bool $truncated = false,
    ) {
    }

    public static function failure(string $url, string $driver, string $error, int $elapsedMs = 0, int $status = 0, array $headers = []): self
    {
        return new self($url, $url, $status, $headers, '', 0, $elapsedMs, $driver, $error);
    }

    public function ok(): bool
    {
        return $this->error === null && $this->status >= 200 && $this->status < 300 && $this->html !== '';
    }

    public function header(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    public function toSummary(): array
    {
        return [
            'requested_url' => $this->requestedUrl,
            'final_url' => $this->finalUrl,
            'status' => $this->status,
            'bytes' => $this->bytes,
            'elapsed_ms' => $this->elapsedMs,
            'driver' => $this->driver,
            'error' => $this->error,
            'redirected' => $this->finalUrl !== $this->requestedUrl,
            'truncated' => $this->truncated,
            'server' => $this->header('server') ?: null,
            'content_type' => $this->header('content-type') ?: null,
        ];
    }
}
