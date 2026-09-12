<?php

namespace App\Services\Mcp\Knowledge;

use RuntimeException;

class McpKnowledgeSyncException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }
}
