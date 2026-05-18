<?php

namespace Tests\Feature\Kyc;

use App\Jobs\Kyc\PushKycStatusJob;
use App\Models\KycSubjectSite;
use App\Services\Kyc\KycStatusFanoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class FanoutThrottleTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithKycFixtures;

    public function test_fanout_dispatches_one_job_per_site_on_kyc_queue(): void
    {
        Bus::fake();

        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);
        $subject = $this->createSubjectForClient($client);
        KycSubjectSite::query()->create([
            'subject_id' => $subject->id,
            'platform_id' => $platform->id,
            'wp_post_id' => $client->wp_post_id,
            'wp_user_id' => $client->wp_user_id,
        ]);

        app(KycStatusFanoutService::class)->dispatchSubject($subject->fresh(['client', 'sites']));

        Bus::assertDispatched(PushKycStatusJob::class, 1);
    }
}
