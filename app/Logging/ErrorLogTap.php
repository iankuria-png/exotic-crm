<?php

namespace App\Logging;

use Illuminate\Log\Logger;
use Monolog\Level;

class ErrorLogTap
{
    public function __invoke(Logger $logger): void
    {
        $logger->pushHandler(new ErrorLogMonologHandler(Level::Error, true));
    }
}
