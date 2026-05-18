<?php

namespace App\Services\Seo\Llm;

class LlmResponse
{
    public string $provider = '';

    public function __construct(
        public readonly string $text,
        public readonly int    $inputTokens  = 0,
        public readonly int    $outputTokens = 0,
    ) {}
}
