<?php

namespace App\Console\Commands;

use App\Services\SubscriptionAudit\SubscriptionAuditBuilder;
use App\Services\SubscriptionAudit\SubscriptionAuditException;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

class BuildSubscriptionAudit extends Command
{
    protected $signature = 'crm:subscription-audit-build {--days=90 : Rolling history, 1 to 90 days} {--from= : Optional UTC inclusive start} {--to= : Optional UTC exclusive end}';

    protected $description = 'Build immutable reporting-only subscription audit snapshots; never changes financial or customer records';

    public function handle(SubscriptionAuditBuilder $builder): int
    {
        try {
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
            $pruned = $builder->prune();
            $this->info('Completed audit run '.$run->run_ref.'; UTC '.$run->from_at->toIso8601String().' to '.$run->to_at->toIso8601String().'; pruned '.$pruned.' older reporting snapshots.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
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
            $this->error('Subscription audit snapshot build failed ('.$failure.'). Previous completed reports remain available.');

            return self::FAILURE;
        }
    }
}
