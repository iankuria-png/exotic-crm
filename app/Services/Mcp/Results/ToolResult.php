<?php

namespace App\Services\Mcp\Results;

class ToolResult
{
    public static function envelope(array $data, array $citations = [], array $meta = []): array
    {
        return ['data' => $data, 'meta' => array_merge([
            'schema_version' => '1.0.0', 'citations' => $citations,
            'coverage' => [], 'caveats' => [],
        ], $meta)];
    }
}
