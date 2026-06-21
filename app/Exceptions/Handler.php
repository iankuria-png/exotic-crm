<?php

namespace App\Exceptions;

use App\Services\ErrorLogRecorder;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\PostTooLargeException;
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
}
