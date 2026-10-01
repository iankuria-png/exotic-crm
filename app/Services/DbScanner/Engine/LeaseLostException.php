<?php

namespace App\Services\DbScanner\Engine;

use RuntimeException;

/**
 * This worker no longer owns the run (generation or lease fenced it out).
 * It must stop without writing observations, metrics or cursors.
 */
class LeaseLostException extends RuntimeException {}
