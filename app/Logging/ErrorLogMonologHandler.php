<?php

namespace App\Logging;

use App\Services\ErrorLogRecorder;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class ErrorLogMonologHandler extends AbstractProcessingHandler
{
    public function __construct(int|string|Level $level = Level::Error, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $context = $record->context ?? [];

        if (!empty($context['__error_log_recorder_skip'])) {
            return;
        }

        $exception = $context['exception'] ?? null;
        if (!$exception instanceof Throwable) {
            $exception = null;
        }

        try {
            app(ErrorLogRecorder::class)->record(
                strtolower($record->level->getName()),
                $exception,
                $record->message,
                $context,
                'log'
            );
        } catch (Throwable) {
            // Never let logging recursion crash the request.
        }
    }
}
