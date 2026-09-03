<?php

namespace App\Services\Ops;

use RuntimeException;

/**
 * A rejected operations setting write. Carries the offending key and the HTTP
 * status the controller should answer with, so the caller is told which bound
 * it violated rather than being handed a generic 422.
 */
class OperationsSettingValidationException extends RuntimeException
{
    public function __construct(
        public readonly string $settingKey,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }
}
