<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The market's WordPress cannot run the profile URL check: either its
 * exotic-crm-sync build predates the alias routes, or the site is unreachable.
 * The Profile URLs screen shows each case differently.
 */
class ProfileUrlHealthUnavailableException extends RuntimeException
{
    public const PLUGIN_OUTDATED = 'plugin_outdated';

    public const SITE_UNREACHABLE = 'site_unreachable';

    public function __construct(
        public readonly string $reason,
        string $message,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function pluginOutdated(?Throwable $previous = null): self
    {
        return new self(
            self::PLUGIN_OUTDATED,
            'This market needs exotic-crm-sync 1.3.13 or later before its profile URLs can be checked.',
            $previous
        );
    }

    public static function unreachable(?Throwable $previous = null): self
    {
        return new self(
            self::SITE_UNREACHABLE,
            'The market site did not answer. Try again in a few minutes.',
            $previous
        );
    }
}
