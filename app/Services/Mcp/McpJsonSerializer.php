<?php

namespace App\Services\Mcp;

class McpJsonSerializer
{
    public function encode(mixed $value): string
    {
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } finally {
            ini_set('serialize_precision', (string) $previous);
        }
    }
}
