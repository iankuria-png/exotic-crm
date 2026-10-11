<?php

namespace App\Console\Commands;

use App\Jobs\RetryUnlinkedPaymentClientJob;
use Illuminate\Console\Command;

class RecoverPaymentClientLinks extends Command
{
    protected $signature = 'crm:recover-payment-client-links';

    protected $description = 'Queue bounded WordPress-identity association recovery; no subscription activation.';

    public function handle(): int
    {
        RetryUnlinkedPaymentClientJob::dispatch()->onQueue('default');
        $this->info('Bounded client association recovery queued.');

        return self::SUCCESS;
    }
}
