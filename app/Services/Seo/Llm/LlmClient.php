<?php

namespace App\Services\Seo\Llm;

interface LlmClient
{
    public function name(): string;

    /**
     * Generate text from a system + user prompt pair.
     *
     * @param  string  $system  System prompt.
     * @param  string  $user    User prompt.
     * @param  array   $opts    Optional overrides (temperature, max_tokens, etc.).
     * @return LlmResponse
     * @throws \Throwable on any provider error.
     */
    public function generate(string $system, string $user, array $opts = []): LlmResponse;
}
