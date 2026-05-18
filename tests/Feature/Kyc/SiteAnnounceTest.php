<?php

namespace Tests\Feature\Kyc;

use App\Models\KycSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class SiteAnnounceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithKycFixtures;

    public function test_site_announce_creates_subjects_idempotently_for_matched_clients(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClientForPlatform($platform);

        $payload = [
            'platform_id' => $platform->id,
            'site_url' => 'https://market.example.test',
            'advertiser_user_ids' => [$client->wp_user_id],
        ];

        $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/site-announce', $payload)
            ->assertOk()
            ->assertJsonPath('matched_clients', 1);

        $this->withHeaders($this->sharedKeyHeaders())->postJson('/api/kyc/site-announce', $payload)
            ->assertOk();

        $this->assertSame(1, KycSubject::query()->count());
    }
}
