<?php

namespace App\Console\Commands\Kyc;

use App\Models\Client;
use App\Services\Kyc\KycSettingsService;
use Illuminate\Console\Command;

class RecomputeExemptions extends Command
{
    protected $signature = 'crm:kyc-recompute-exemptions';
    protected $description = 'Recompute client KYC exemption state from active deals and settings.';

    public function handle(KycSettingsService $settingsService): int
    {
        $updated = 0;

        // Stream in batches to keep memory flat: the clients table grows without
        // bound, and hydrating it whole (with eager relations) overran the CLI
        // memory_limit and crashed the hourly scheduler run.
        Client::query()
            ->with(['activeDeal.product', 'kycSubject'])
            ->chunkById(200, function ($clients) use ($settingsService, &$updated) {
                foreach ($clients as $client) {
                    $required = !$settingsService->isExempt($client);
                    if ((bool) $client->kyc_required === $required) {
                        continue;
                    }

                    $client->forceFill(['kyc_required' => $required])->save();
                    if (!$required && $client->kycSubject) {
                        $client->kycSubject->forceFill(['status' => 'unverified'])->save();
                    }
                    $updated++;
                }
            });

        $this->info('Updated ' . $updated . ' clients.');

        return self::SUCCESS;
    }
}
