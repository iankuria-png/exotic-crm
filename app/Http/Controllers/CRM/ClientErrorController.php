<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Services\ErrorLogRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ingests browser-side failures (React crashes, unhandled rejections,
 * infrastructure errors) into the existing Error Logs subsystem under the
 * `client` source, so they are visible/searchable alongside server errors.
 * Best-effort by design: the SPA fires-and-forgets here and the route is
 * throttled so a crash loop can't flood the store.
 */
class ClientErrorController extends Controller
{
    public function store(Request $request, ErrorLogRecorder $recorder): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'category' => ['nullable', 'string', 'max:50'],
            'url' => ['nullable', 'string', 'max:1000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'component' => ['nullable', 'string', 'max:200'],
            'app_build' => ['nullable', 'string', 'max:100'],
            'request_id' => ['nullable', 'string', 'max:80'],
        ]);

        $context = array_filter([
            'category' => $validated['category'] ?? null,
            'client_url' => $validated['url'] ?? null,
            'component' => $validated['component'] ?? null,
            'app_build' => $validated['app_build'] ?? null,
            'request_id' => $validated['request_id'] ?? null,
            'user_agent' => mb_strimwidth((string) $request->userAgent(), 0, 400, ''),
        ], fn ($value) => $value !== null && $value !== '');

        if (! empty($validated['stack'])) {
            $context['stack'] = $validated['stack'];
        }

        $recorder->record('error', null, '[client] ' . $validated['message'], $context, 'client');

        return response()->json(['status' => 'recorded'], 202);
    }

    /**
     * First-party public-IP echo for the Network Check diagnostics page, so the
     * browser never has to call a third-party IP service (privacy-safe).
     */
    public function whoamiIp(Request $request): JsonResponse
    {
        return response()->json([
            'ip' => $request->ip(),
            'time' => now()->toIso8601String(),
        ]);
    }
}
