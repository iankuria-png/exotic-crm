<?php

namespace App\Services\Kyc\KycStorage;

class UploadTarget
{
    public function __construct(
        public readonly string $mode,
        public readonly string $url,
        public readonly string $method = 'POST',
        public readonly array $headers = [],
        public readonly array $fields = [],
        public readonly ?string $expiresAt = null,
        public readonly array $meta = [],
    ) {
    }

    public function toArray(): array
    {
        $payload = [
            'mode' => $this->mode,
            'upload' => [
                'url' => $this->url,
                'method' => $this->method,
                'headers' => (object) $this->headers,
                'fields' => (object) $this->fields,
                'expires_at' => $this->expiresAt,
            ],
        ];

        return array_merge($payload, $this->meta);
    }
}
