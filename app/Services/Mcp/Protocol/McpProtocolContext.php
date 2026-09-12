<?php

namespace App\Services\Mcp\Protocol;

class McpProtocolContext
{
    public function __construct(public readonly string $version, private readonly bool $enhanced = false) {}

    public function enhanced(): bool
    {
        return $this->enhanced;
    }
}
