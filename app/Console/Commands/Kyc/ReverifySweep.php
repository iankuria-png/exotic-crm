<?php

namespace App\Console\Commands\Kyc;

use App\Models\KycSubject;
use App\Services\Kyc\KycSubjectService;
use Illuminate\Console\Command;

class ReverifySweep extends Command
{
    protected $signature = 'crm:kyc-reverify-sweep';
    protected $description = 'Mark approved KYC subjects for re-verification when they have expired.';

    public function handle(KycSubjectService $subjectService): int
    {
        $subjects = KycSubject::query()
            ->with('client')
            ->where('status', KycSubject::STATUS_APPROVED)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();

        foreach ($subjects as $subject) {
            $subjectService->reRequest($subject, null, 'Scheduled re-verification sweep');
        }

        $this->info('Queued re-verification for ' . $subjects->count() . ' subjects.');

        return self::SUCCESS;
    }
}
