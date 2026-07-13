<?php

namespace App\Exceptions;

use App\Http\Middleware\AttachRequestId;
use App\Services\ErrorLogRecorder;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\JsonResponse;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            app(ErrorLogRecorder::class)->recordException($e, [
                '__error_log_recorder_skip' => true,
            ]);
        });

        $this->renderable(function (PostTooLargeException $e, $request) {
            if ($request->is('api/crm/*')) {
                return response()->json([
                    'message' => 'This file is too large for the server. Ask an admin to raise the upload limit.',
                ], 413);
            }

            return null;
        });
    }

    /**
     * Delegate to Laravel's default rendering (which already maps every
     * exception to the correct status + JSON/HTML), then — for CRM API JSON
     * errors only — decorate the payload with a stable `code` category and the
     * request correlation id. Purely additive: `message` and the `errors` bag
     * are preserved verbatim so existing frontend readers keep working, and
     * the Ads API (`api/*` outside `api/crm/*`) is never touched.
     */
    public function render($request, Throwable $e)
    {
        $response = parent::render($request, $e);

        if ($response instanceof JsonResponse && $request->is('api/crm/*')) {
            return $this->normalizeCrmError($request, $response);
        }

        return $response;
    }

    private function normalizeCrmError($request, JsonResponse $response): JsonResponse
    {
        $status = $response->getStatusCode();
        $data = $response->getData(true);

        if (! is_array($data)) {
            $data = ['message' => is_string($data) ? $data : 'Request failed.'];
        }

        if (! isset($data['code'])) {
            $data['code'] = $this->categoryForStatus($status);
        }

        $requestId = $request->attributes->get(AttachRequestId::ATTRIBUTE);
        if ($requestId && ! isset($data['request_id'])) {
            $data['request_id'] = $requestId;
        }

        // Never leak an opaque "Server Error" for 5xx in production — give the
        // user something actionable while the real detail lives in Error Logs.
        if ($status >= 500 && ! config('app.debug')) {
            if (! isset($data['message']) || $data['message'] === '' || $data['message'] === 'Server Error') {
                $data['message'] = 'Something went wrong on our side. Please try again, and share the request ID with support if it persists.';
            }
        }

        $response->setData($data);

        return $response;
    }

    private function categoryForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => 'auth_error',
            $status === 403 => 'permission_error',
            $status === 404 => 'not_found',
            $status === 419 => 'session_expired',
            $status === 422 => 'validation_error',
            $status === 429 => 'rate_limited',
            $status >= 500 => 'server_error',
            $status >= 400 => 'bad_request',
            default => 'error',
        };
    }
}
