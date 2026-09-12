<?php

namespace App\Services\Mcp\Protocol;

class McpProtocolContext
{
    public function __construct(public readonly string $version) {}

    public function modern(): bool
    {
        return $this->version === '2026-07-28';
    }
}
