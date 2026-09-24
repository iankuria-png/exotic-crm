<?php

namespace App\Http\Controllers\CRM\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;

/**
 * Shared WordPress error mapping for the story controllers.
 */
trait RespondsToWordPressStories
{
    protected function isMissingRoute(RequestException $exception): bool
    {
        return $exception->response?->status() === 404
            && ($exception->response->json('code') ?? null) === 'rest_no_route';
    }

    protected function wordpressFailure(RequestException $exception, string $message): JsonResponse
    {
        $status = $exception->response?->status() ?? 502;
        $payload = $exception->response?->json();

        // A WordPress 401/403 is the market's CRM credentials, not the staff
        // session; passing it through would look like a CRM sign-out.
        if (in_array($status, [401, 403], true)) {
            return response()->json([
                'message' => 'WordPress refused the CRM connection for this market.',
                'error' => is_array($payload) ? ($payload['message'] ?? null) : null,
            ], 502);
        }

        if ($status >= 400 && $status < 500) {
            return response()->json(
                is_array($payload) ? $payload : ['message' => $message],
                $status
            );
        }

        return response()->json([
            'message' => $message,
            'error' => is_array($payload) ? ($payload['message'] ?? $exception->getMessage()) : $exception->getMessage(),
        ], 502);
    }
}
