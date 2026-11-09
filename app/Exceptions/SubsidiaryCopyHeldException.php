<?php

namespace App\Exceptions;

/** Internal signal: stop a profile copy run for review without new destination effects. */
class SubsidiaryCopyHeldException extends \RuntimeException {}
