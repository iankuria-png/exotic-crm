<?php

namespace App\Services\Seo\Llm;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

class LlmTransport
{
    public static function post(
        PendingRequest $request, string $url, array $payload, array $opts,
        string $provider, string $model, string $credential, int $defaultTimeout,
    ): Response {
        $run = $opts['_bio_run'] ?? null;

        return $run instanceof BioGenerationRun
            ? $run->send($request, $url, $payload, $provider, $model, $credential)
            : $request->timeout($defaultTimeout)->post($url, $payload);
    }
}
