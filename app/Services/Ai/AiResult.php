<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Services\Seo\Llm\LlmResponse;

/**
 * Wraps the raw LLM response together with the persisted ai_interactions row,
 * so callers can attach derived fields (generated_sql, result_summary) and save.
 */
class AiResult
{
    public function __construct(
        public readonly LlmResponse $response,
        public readonly AiInteraction $interaction,
    ) {}

    public function text(): string
    {
        return $this->response->text;
    }
}
