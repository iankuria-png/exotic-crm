<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentRecoveryCase;
use App\Services\PaymentFulfillmentRecoveryService;
use App\Services\PaymentRecoveryCaseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverPaidPaymentService extends Command
{
    protected $signature = 'crm:recover-paid-service {--limit=50} {--include-history} {--after=0}';

    protected $description = 'Maintain owned receipt recovery cases and resume accepted, explicitly enabled purchases.';

    public function handle(): int
    {
        if (! Schema::hasTable('payment_recovery_cases')) {
            return self::SUCCESS;
        }
        $limit = max(1, min(200, (int) $this->option('limit')));
        $discovered = Payment::businessVisible()->subscriptionRevenue()->where('amount', '>', 0)->where('id', '>', (int) $this->option('after'))
            ->when(! $this->option('include-history'), fn ($q) => $q->where('created_at', '>=', now()->subDays(30)))
            ->where(function ($q) {
                $q->whereIn('status', Payment::SUCCESSFUL_STATUSES)->orWhere('payment_data->settlement_assessment->settled_amount', '>', 0);
            })
            ->whereDoesntHave('recoveryCase')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('deals')->whereColumn('deals.payment_id', 'payments.id')->whereColumn('deals.platform_id', 'payments.platform_id')->whereNotNull('deals.activated_at'))
            ->whereDoesntHave('attribution', fn ($q) => $q->where('attribution_state', 'archived')->whereNotNull('evidence_json->benefit->activated_at'))
            ->orderBy('id')->limit($limit)->get();
        foreach ($discovered as $p) {
            app(PaymentRecoveryCaseService::class)->synchronize($p);
        }
        $this->line(json_encode(['discovered' => $discovered->count(), 'next_after' => $discovered->last()?->id]));
        $cases = PaymentRecoveryCase::where('state', '!=', 'closed')->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))->orderBy('next_retry_at')->orderBy('id')->limit($limit)->get();
        foreach ($cases as $c) {
            try {
                if ($p = $c->payment) {
                    app(PaymentRecoveryCaseService::class)->synchronize($p);
                    app(PaymentFulfillmentRecoveryService::class)->resume($p);
                }
            } catch (\Throwable $e) {
                report($e);
            }
            if ($c->fresh()->state !== 'closed') {
                $c->update(['next_retry_at' => now()->addMinutes(5)]);
            }
        }
        $this->info('Recovery cases checked: '.$cases->count());

        return self::SUCCESS;
    }
}
