<?php

namespace App\Services\Ops;

/** Parse a UID-qualified process table without treating foreign accounts as CRM load. */
final class AccountProcessListing
{
    /** @return array<int, string>|null */
    public static function parse(string $output, int $uid, string $ownCommand): ?array
    {
        $lines = [];
        $sawOwnProcess = false;

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (! preg_match('/^\s*(\d+)\s+(.+)$/', $line, $match)) {
                continue;
            }
            if ((int) $match[1] !== $uid) {
                continue;
            }

            $command = trim($match[2]);
            $sawOwnProcess = $sawOwnProcess || $command === trim($ownCommand);
            $lines[] = $command;
        }

        // Missing ownership/our known process is an unavailable sample, never zero pressure.
        return $sawOwnProcess ? $lines : null;
    }
}
