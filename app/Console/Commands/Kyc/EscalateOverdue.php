<?php

namespace App\Console\Commands\Kyc;

use App\Models\KycSubject;
use App\Services\AuditService;
use App\Services\Kyc\KycSettingsService;
use App\Services\WpSyncService;
use Illuminate\Console\Command;

class EscalateOverdue extends Command
{
    protected $signature = 'crm:kyc-escalate-overdue';
    protected $description = 'Apply opt-in KYC escalation rules to overdue subjects.';

    public function handle(KycSettingsService $settingsService, AuditService $auditService): int
    {
        $subjects = KycSubject::query()
            ->with('client')
            ->whereNotIn('status', [KycSubject::STATUS_APPROVED])
            ->whereHas('client', fn ($builder) => $builder->where('kyc_required', true))
            ->whereNotNull('grace_started_at')
            ->get();

        $processed = 0;
        foreach ($subjects as $subject) {
            $client = $subject->client;
            if (!$client) {
                continue;
            }

            $deadline = optional($subject->grace_started_at)->copy()?->addDays($settingsService->graceDaysForPlatform((int) $client->platform_id));
            if (!$deadline || $deadline->isFuture()) {
                continue;
            }

            $rule = $settingsService->escalationRuleForPlatform((int) $client->platform_id);
            $subject->forceFill([
                'last_escalation_at' => now(),
                'last_escalation_rule' => $rule,
            ])->save();

            if ($rule === 'remove_badge') {
                $client->forceFill(['verified' => false])->save();
                $auditService->record([
                    'platform_id' => (int) $client->platform_id,
                    'action' => 'kyc.badge_removed',
                    'entity_type' => 'kyc_subject',
                    'entity_id' => (int) $subject->id,
                    'reason' => 'Automatic KYC overdue escalation removed the verified badge.',
                ]);
            }

            if ($rule === 'auto_suspend' && (int) $client->wp_post_id > 0) {
                WpSyncService::forPlatform((int) $client->platform_id)->updateClientProfile((int) $client->wp_post_id, [
                    'notactive' => '1',
                ]);
                $auditService->record([
                    'platform_id' => (int) $client->platform_id,
                    'action' => 'kyc.auto_suspended',
                    'entity_type' => 'kyc_subject',
                    'entity_id' => (int) $subject->id,
                    'reason' => 'Automatic KYC overdue escalation set the linked WordPress profile to private.',
                ]);
            }

            if ($rule === 'notify_only') {
                $auditService->record([
                    'platform_id' => (int) $client->platform_id,
                    'action' => 'kyc.notify_only',
                    'entity_type' => 'kyc_subject',
                    'entity_id' => (int) $subject->id,
                    'reason' => 'Automatic KYC overdue escalation remained in notify_only mode.',
                ]);
            }

            $processed++;
        }

        $this->info('Processed ' . $processed . ' overdue KYC subjects.');

        return self::SUCCESS;
    }
}
