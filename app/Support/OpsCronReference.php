<?php

namespace App\Support;

/**
 * The cron lines Settings shows on screen.
 *
 * These strings used to be hardcoded in two controllers and both were wrong in
 * ways that mattered: the scheduler line omitted `flock`, and the queue worker
 * line named a pre-391954e lane list. Both were rendered with a copy button, so
 * a wrong string here is one click away from being installed on the host.
 * They live in one place now, are built from the real configuration, and the
 * queue worker line is explicitly not something to install.
 */
class OpsCronReference
{
    /**
     * cPanel's `php` is not PHP 8.2. `config('deployment.php_binary')` defaults
     * to a bare `php`, which is fine for exec inside a request but wrong in a
     * crontab, so fall back to the interpreter path documented in
     * docs/DEPLOYMENT.md when nothing more specific is configured.
     */
    public const FALLBACK_PHP_BINARY = '/opt/cpanel/ea-php82/root/usr/bin/php';

    public static function phpBinary(): string
    {
        $binary = trim((string) config('deployment.php_binary', ''));

        if ($binary === '' || $binary === 'php') {
            return self::FALLBACK_PHP_BINARY;
        }

        return $binary;
    }

    public static function lockPath(): string
    {
        return storage_path('scheduler.lock');
    }

    /**
     * The one cron line the host should actually have.
     *
     * `flock -n` is load-bearing, not decoration: without it a tick that
     * outlives its minute leaves a `schedule:run` running while cron starts the
     * next one, the processes accumulate, and the account runs out of entry
     * processes — which is the 3 September 2026 outage.
     */
    public static function schedulerCron(): string
    {
        return sprintf(
            "* * * * * /usr/bin/flock -n %s -c 'cd %s && %s artisan schedule:run' >> /dev/null 2>&1",
            self::lockPath(),
            base_path(),
            self::phpBinary()
        );
    }

    /**
     * The four queue-worker lanes the scheduler already runs.
     *
     * Returned for display only. Adding these to crontab alongside the
     * scheduler starts a second set of workers on lanes that already have one,
     * which is the duplication that exhausted the entry-process limit before.
     *
     * @return array<int, array{lane:string, connection:string, queues:string, command:string}>
     */
    public static function queueWorkerReference(): array
    {
        $connection = (string) config('queue.default', 'sync');

        if ($connection === 'sync') {
            return [];
        }

        $lanes = [
            ['lane' => 'fast', 'connection' => $connection, 'queues' => 'push,alerts,default,kyc-fanout', 'options' => '--max-time=55 --max-jobs=100 --tries=3 --sleep=3'],
            ['lane' => 'sync', 'connection' => $connection, 'queues' => 'sync-clients,sync-clients-reconcile', 'options' => '--max-time=55 --max-jobs=50 --tries=3 --sleep=3'],
            ['lane' => 'optimize', 'connection' => $connection, 'queues' => 'auto_optimize', 'options' => '--max-time=55 --max-jobs=30 --tries=3 --sleep=3'],
            ['lane' => 'heavy', 'connection' => 'database_long', 'queues' => 'heavy', 'options' => '--max-time=55 --max-jobs=10 --tries=2 --sleep=3'],
        ];

        return array_map(fn (array $lane): array => [
            'lane' => $lane['lane'],
            'connection' => $lane['connection'],
            'queues' => $lane['queues'],
            'command' => sprintf(
                '%s artisan queue:work %s --queue=%s %s',
                self::phpBinary(),
                $lane['connection'],
                $lane['queues'],
                $lane['options']
            ),
        ], $lanes);
    }

    /**
     * The scheduler cron actually installed on this host, when it can be read.
     *
     * cPanel accounts differ in whether `shell_exec` is permitted, so an
     * unreadable crontab reports `available: false` rather than pretending the
     * cron is missing.
     *
     * @return array{available:bool, line:string|null, has_flock:bool|null, matches_expected:bool|null, message:string}
     */
    public static function detectInstalledSchedulerCron(): array
    {
        $unavailable = [
            'available' => false,
            'line' => null,
            'has_flock' => null,
            'matches_expected' => null,
            'message' => 'The crontab could not be read from here. Check it in cPanel → Cron Jobs.',
        ];

        if (! function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return $unavailable;
        }

        try {
            $output = @shell_exec('crontab -l 2>/dev/null');
        } catch (\Throwable) {
            return $unavailable;
        }

        if (! is_string($output) || trim($output) === '') {
            return $unavailable;
        }

        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim((string) $line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, 'schedule:run')) {
                continue;
            }

            $hasFlock = str_contains($line, 'flock');

            return [
                'available' => true,
                'line' => $line,
                'has_flock' => $hasFlock,
                'matches_expected' => $line === self::schedulerCron(),
                'message' => $hasFlock
                    ? 'A scheduler cron is installed and wrapped in flock.'
                    : 'The installed scheduler cron is NOT wrapped in flock. A slow tick will stack on the next one.',
            ];
        }

        return [
            'available' => true,
            'line' => null,
            'has_flock' => null,
            'matches_expected' => false,
            'message' => 'No schedule:run entry was found in the crontab.',
        ];
    }
}
