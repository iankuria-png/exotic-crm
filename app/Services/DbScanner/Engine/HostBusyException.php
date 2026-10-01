<?php

namespace App\Services\DbScanner\Engine;

use RuntimeException;

class HostBusyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another scanner session is using this database host; try again shortly.');
    }
}
