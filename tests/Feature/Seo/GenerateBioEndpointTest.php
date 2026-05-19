<?php

namespace Tests\Feature\Seo;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\Seo\LinkCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GenerateBioEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_crm_generate_bio_accepts_edit_profile_payload_shape(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        config([
            'services.seo_engine.enabled' => true,
            'services.seo_engine.providers' => [],
            'services.seo_engine.platform_allowlist' => [],
        ]);

        $platform = Platform::factory()->create();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Test-PM',
            'city' => 'Nairobi',
        ]);

        $catalog = \Mockery::mock(LinkCatalogService::class);
        $catalog->shouldReceive('forPlatform')->andReturn([]);
        $this->app->instance(LinkCatalogService::class, $catalog);

        $response = $this->postJson('/api/crm/seo/generate-bio', [
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'profile_snapshot' => [
                'name' => 'Test-PM',
                'phone' => '254769912227',
                'gender' => '1',
                'ethnicity' => '3',
                'height' => '168',
                'build' => '4',
                'bio' => 'Hello, gentlemen! I’m Testing PM.',
                'availability' => ['1', '2'],
                'services' => ['GFE', ['ignored nested array']],
            ],
            'save' => false,
        ]);

        $response->assertOk()
            ->assertJsonStructure(['bio_html', 'score', 'breakdown', 'provider_used'])
            ->assertJsonPath('provider_used', 'template_fallback');

        $this->assertStringNotContainsString('build type', $response->json('bio_html'));
        $this->assertStringNotContainsString('type 4', $response->json('bio_html'));
        $this->assertStringContainsString('curvy', strtolower($response->json('bio_html')));
    }
}
