<?php

namespace App\Console\Commands;

use App\Services\SubscriptionAudit\SubscriptionAuditBuilder;
use App\Services\SubscriptionAudit\SubscriptionAuditException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BuildSubscriptionAudit extends Command
{
    protected $signature = 'crm:subscription-audit-build {--days=90 : Rolling history, 1 to 90 days} {--from= : Optional UTC inclusive start} {--to= : Optional UTC exclusive end}';

    protected $description = 'Build immutable reporting-only subscription audit snapshots; never changes financial or customer records';

    public function handle(SubscriptionAuditBuilder $builder): int
    {
        $lock = null;
        $connection = DB::connection();
        try {
            if ($connection->getDriverName() === 'mysql') {
                $name = 'exotic:audit:'.substr(hash('sha256', $connection->getDatabaseName()), 0, 48);
                if ((int) $connection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])->acquired !== 1) {
                    $this->warn('Another subscription audit build or cleanup is running. This invocation was skipped; completed reports remain available.');

                    return self::SUCCESS;
                }
                $lock = $name;
            }
            // The bounded 90-day projection is a background CLI workload. Reserve
            // its own ceiling rather than inheriting a 128 MB web-process limit.
            $configured = trim((string) ini_get('memory_limit'));
            $bytes = (int) $configured;
            $suffix = strtolower(substr($configured, -1));
            $bytes *= match ($suffix) {
                'g' => 1073741824, 'm' => 1048576, 'k' => 1024, default => 1
            };
            if ($bytes > 0 && $bytes < 384 * 1048576) {
                ini_set('memory_limit', '384M');
                if ((string) ini_get('memory_limit') !== '384M') {
                    throw new \RuntimeException('The reporting CLI memory ceiling is unavailable.');
                }
            }
            $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
            if ($days === false) {
                throw new \InvalidArgumentException('days must be an integer.');
            }
            $from = $this->option('from') ? CarbonImmutable::parse($this->option('from'), 'UTC') : null;
            $to = $this->option('to') ? CarbonImmutable::parse($this->option('to'), 'UTC') : null;
            $run = $builder->build($days, $from, $to);
            $this->info('Completed audit run '.$run->run_ref.'; UTC '.$run->from_at->toIso8601String().' to '.$run->to_at->toIso8601String().'.');
            try {
                $pruned = $builder->prune();
                $this->info('Pruned '.$pruned.' older reporting snapshots.');
            } catch (\Throwable $exception) {
                $this->warn('The completed snapshot is available; old-snapshot cleanup failed ('.$this->failureLabel($exception).'). Cleanup will retry on the next build.');
            }

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error('Subscription audit snapshot build failed ('.$this->failureLabel($exception).'). Previous completed reports remain available.');

            return self::FAILURE;
        } finally {
            if ($lock !== null) {
                try {
                    $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
                } catch (\Throwable $exception) {
                    // A closed database connection also releases its advisory lock.
                    $this->warn('Audit lock release could not be confirmed ('.$this->failureLabel($exception).').');
                }
            }
        }
    }

    private function failureLabel(\Throwable $exception): string
    {
        $failure = class_basename($exception);
        if ($exception instanceof QueryException) {
            // SQL and bindings may contain private client/payment evidence.
            $state = (string) ($exception->errorInfo[0] ?? '');
            $driver = (string) ($exception->errorInfo[1] ?? '');
            if (preg_match('/^[A-Z0-9]{5}$/D', $state) && ctype_digit($driver)) {
                $failure .= '; SQLSTATE '.$state.'; driver '.$driver;
            }
        } elseif ($exception instanceof SubscriptionAuditException) {
            $failure .= '; '.$exception->reason;
        }

        return $failure;
    }
}
