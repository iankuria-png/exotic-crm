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
        $clients = Client::query()->with(['activeDeal.product', 'kycSubject'])->get();
        $updated = 0;

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

        $this->info('Updated ' . $updated . ' clients.');

        return self::SUCCESS;
    }
}
