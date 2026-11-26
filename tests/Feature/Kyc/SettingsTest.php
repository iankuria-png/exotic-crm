<?php

namespace Tests\Feature\Kyc;

use App\Models\KycSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Kyc\Concerns\InteractsWithKycFixtures;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use InteractsWithKycFixtures;
    use RefreshDatabase;

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

    public function test_queue_summary_is_scoped_and_skips_storage_and_billing_queries(): void
    {
        $allowed = $this->createPlatform();
        $hidden = $this->createPlatform();
        $this->setKycSettings(['enabled_platform_ids' => [$allowed->id, $hidden->id]]);
        $this->actingAsKycUser('sales', [$allowed->id]);
        \DB::enableQueryLog();
        $response = $this->getJson('/api/crm/kyc/settings?summary=1')->assertOk();
        $sql = implode(' ', array_column(\DB::getQueryLog(), 'query'));
        \DB::disableQueryLog();
        $response->assertJsonCount(1, 'platforms')->assertJsonPath('platforms.0.id', $allowed->id)
            ->assertJsonMissingPath('total_blob_bytes')->assertJsonMissingPath('s3_health')
            ->assertJsonMissingPath('settings.ai_review');
        $this->assertStringNotContainsString('kyc_document_blobs', $sql);
        $this->assertStringNotContainsString('billing_', $sql);
        $this->actingAsKycUser();
        $this->getJson('/api/crm/kyc/settings')->assertOk()->assertJsonStructure(['total_blob_bytes', 's3_health', 'settings' => ['ai_review']]);
    }
}
