<?php

namespace Tests\Feature\Seo;

use App\Models\IntegrationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SeoBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_deepseek_balance_is_returned_when_api_responds(): void
    {
        IntegrationSetting::create([
            'key' => 'seo_engine',
            'value' => [
                'enabled' => true,
                'providers' => [
                    'deepseek' => ['api_key' => 'sk-test-deepseek', 'model' => 'deepseek-chat'],
                ],
            ],
        ]);

        Http::fake([
            'api.deepseek.com/user/balance' => Http::response([
                'is_available' => true,
                'balance_infos' => [[
                    'currency'           => 'USD',
                    'total_balance'      => '12.34',
                    'granted_balance'    => '0.00',
                    'topped_up_balance'  => '12.34',
                ]],
            ], 200),
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $response = $this->getJson('/api/crm/settings/seo-engine/balance?provider=deepseek');

        $response->assertOk()
            ->assertJsonPath('provider', 'deepseek')
            ->assertJsonPath('supported', true)
            ->assertJsonPath('balance', '12.34')
            ->assertJsonPath('currency', 'USD');
    }

    public function test_balance_endpoint_marks_claude_as_unsupported(): void
    {
        IntegrationSetting::create([
            'key' => 'seo_engine',
            'value' => [
                'enabled' => true,
                'providers' => [
                    'claude' => ['api_key' => 'sk-test-claude', 'model' => 'claude-3-5-sonnet'],
                ],
            ],
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->getJson('/api/crm/settings/seo-engine/balance?provider=claude')
            ->assertOk()
            ->assertJsonPath('supported', false);
    }

    public function test_balance_endpoint_reports_no_key_when_blank(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        // No DB settings, and we explicitly ensure env stays empty for the test
        config([]);
        putenv('DEEPSEEK_API_KEY=');

        $this->getJson('/api/crm/settings/seo-engine/balance?provider=deepseek')
            ->assertOk()
            ->assertJsonPath('supported', true)
            ->assertJsonStructure(['error']);
    }

    public function test_balance_endpoint_rejects_non_admin(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'sales', 'status' => 'active']));

        $this->getJson('/api/crm/settings/seo-engine/balance?provider=deepseek')
            ->assertStatus(403);
    }
}
