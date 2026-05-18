<?php

namespace App\Services\Kyc;

use App\Jobs\Kyc\PushKycStatusJob;
use App\Models\KycSubject;
use App\Models\KycSubjectSite;
use App\Services\WpSyncService;

class KycStatusFanoutService
{
    public function __construct(private readonly KycSubjectService $subjectService)
    {
    }

    public function dispatchSubject(KycSubject $subject): void
    {
        $subject->loadMissing(['sites', 'client']);
        foreach ($subject->sites as $site) {
            PushKycStatusJob::dispatch((int) $site->id);
        }
    }

    public function pushTo(KycSubjectSite $site): array
    {
        $site->loadMissing(['subject.client']);
        $subject = $site->subject;
        if (!$subject || !$subject->client) {
            throw new \RuntimeException('Subject site is missing its subject or client.');
        }

        $payload = $this->subjectService->buildStatusPayload($subject);
        $response = WpSyncService::forPlatform((int) $site->platform_id)
            ->pushKycSubjectStatus((int) ($site->wp_post_id ?? $subject->client->wp_post_id), $payload);

        $site->forceFill([
            'last_synced_at' => now(),
            'last_sync_status' => 'ok',
            'last_sync_error' => null,
        ])->save();

        return $response;
    }
}
