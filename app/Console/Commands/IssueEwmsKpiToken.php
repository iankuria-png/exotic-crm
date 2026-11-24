<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Ewms\EwmsKpiAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class IssueEwmsKpiToken extends Command
{
    protected $signature = 'ewms:kpi-token {--user-id= : Existing active integration account ID} {--output= : New filename under storage/app/private/ewms} {--expires-in=90 : Token validity in days, 1–365}';

    protected $description = 'Issue one restricted EWMS token to an existing integration identity; preserve every existing token';

    public function handle(): int
    {
        $userId = filter_var($this->option('user-id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $days = filter_var($this->option('expires-in'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 365]]);
        $filename = (string) $this->option('output');
        if (! $userId || ! $days || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,100}\.json$/D', $filename)) {
            $this->error('Supply --user-id, --expires-in=1..365 and --output=<new-filename.json>.');

            return self::FAILURE;
        }
        $user = User::find($userId);
        if (! $user || $user->role !== EwmsKpiAccess::ROLE || ! $user->isActive()) {
            $this->error('Select an existing active ewms_kpi_integration account. No account was changed.');

            return self::FAILURE;
        }
        $directory = storage_path('app/private/ewms');
        if (is_link($directory) || (is_dir($directory) && realpath($directory) !== $directory)) {
            $this->error('Private output directory must not be a symlink.');

            return self::FAILURE;
        }
        $mask = umask(0077);
        $handle = null;
        $path = $directory.'/'.$filename;
        try {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
                throw new RuntimeException('Private directory unavailable.');
            }
            $handle = @fopen($path, 'x');
            if ($handle === false || ! chmod($path, 0600)) {
                throw new RuntimeException('Use a new private output filename.');
            }
            DB::transaction(function () use ($user, $days, $handle): void {
                $issued = $user->createToken('ewms-kpi', [EwmsKpiAccess::ABILITY], now()->addDays($days));
                $content = json_encode(['token' => $issued->plainTextToken, 'expires_at' => $issued->accessToken->expires_at->toIso8601String()], JSON_THROW_ON_ERROR)."\n";
                if (fwrite($handle, $content) !== strlen($content) || ! fflush($handle)) {
                    throw new RuntimeException('Private output could not be written.');
                }
            });
            $this->info('Restricted token saved to storage/app/private/ewms/'.$filename.'. Existing tokens were preserved.');

            return self::SUCCESS;
        } catch (Throwable) {
            if (is_resource($handle)) {
                fclose($handle);
                $handle = null;
                @unlink($path);
            }
            $this->error('Token issuance failed; no token value was printed. Check the private output directory and account.');

            return self::FAILURE;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            umask($mask);
        }
    }
}
