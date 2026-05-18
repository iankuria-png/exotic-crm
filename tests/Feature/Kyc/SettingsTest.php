<?php

namespace Tests\Feature\Kyc;

use App\Models\KycSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithKycFixtures;

    public function test_sub_admin_cannot_enable_auto_suspend(): void
    {
        $platform = $this->createPlatform();
        $this->actingAsKycUser('sub_admin', [$platform->id]);

        $response = $this->putJson('/api/crm/kyc/settings', [
            'enabled_platform_ids' => [$platform->id],
            'escalation_rule_per_platform' => [(string) $platform->id => 'auto_suspend'],
        ]);

        $response->assertStatus(422);
    }

    public function test_admin_can_update_kyc_settings(): void
    {
        $platform = $this->createPlatform();
        $this->actingAsKycUser('admin', [$platform->id]);

        $response = $this->putJson('/api/crm/kyc/settings', [
            'enabled_platform_ids' => [$platform->id],
            'required_document_kinds' => ['id_front', 'selfie'],
            'search_boost_enabled' => true,
        ]);

        $response->assertOk();
        $this->assertSame([$platform->id], KycSetting::query()->firstOrFail()->enabled_platform_ids);
    }
}
