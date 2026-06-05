<?php

namespace Tests\Feature\CRM;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Platform;
use App\Models\Template;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientQuickRepliesTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_returns_rendered_platform_scoped_quick_replies_and_normalized_whatsapp_phone(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'phone_prefix' => '254',
        ]);
        $otherPlatform = Platform::factory()->create(['name' => 'Uganda']);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Amina',
            'phone_normalized' => '0748612016',
        ]);

        Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'expired',
            'expires_at' => now()->subDays(2),
        ]);
        $this->createTemplate('Global win-back', 'win_back', 'Expired {{days_since_expiry}} days ago for {{client_name}}.');
        $this->createTemplate('Global welcome', 'welcome', 'Hi {{client_name}} from {{platform_name}}.');
        $this->createTemplate('Kenya payment', 'payment', 'Activate in Kenya.', $platform->id);
        $this->createTemplate('Uganda payment', 'payment', 'Activate in Uganda.', $otherPlatform->id);

        $user = $this->createUser('sales', [$platform->id]);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/crm/clients/{$client->id}/quick-replies");

        $response->assertOk()
            ->assertJsonPath('situation', 'expired')
            ->assertJsonPath('whatsapp_phone', '254748612016')
            ->assertJsonFragment([
                'title' => 'Global win-back',
                'category' => 'win_back',
                'suggested' => true,
            ])
            ->assertJsonMissing(['title' => 'Uganda payment']);

        $this->assertStringContainsString(
            'Expired 2 days ago for Amina.',
            collect($response->json('messages'))->firstWhere('title', 'Global win-back')['body'] ?? ''
        );
    }

    public function test_client_market_access_is_required(): void
    {
        $platform = Platform::factory()->create();
        $otherPlatform = Platform::factory()->create();
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        Sanctum::actingAs($this->createUser('sales', [$otherPlatform->id]));

        $this->getJson("/api/crm/clients/{$client->id}/quick-replies")
            ->assertForbidden();
    }

    public function test_never_paid_endpoint_filters_expiry_dependent_template(): void
    {
        $platform = Platform::factory()->create(['name' => 'Kenya']);
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Nia',
        ]);
        $this->createTemplate('Broken win-back for no expiry', 'win_back', 'Expired {{days_since_expiry}} days ago.');
        $this->createTemplate('Payment nudge', 'payment', 'Hi {{client_name}}, activate your profile.');
        $this->createTemplate('Welcome fallback', 'welcome', 'Hi {{client_name}} from {{platform_name}}.');

        Sanctum::actingAs($this->createUser('sales', [$platform->id]));

        $response = $this->getJson("/api/crm/clients/{$client->id}/quick-replies");

        $response->assertOk()
            ->assertJsonPath('situation', 'never_paid')
            ->assertJsonFragment([
                'title' => 'Payment nudge',
                'category' => 'payment',
                'suggested' => true,
            ])
            ->assertJsonMissing(['title' => 'Broken win-back for no expiry']);
    }

    public function test_settings_template_quick_reply_flag_persists(): void
    {
        $admin = $this->createUser('admin');
        $template = $this->createTemplate('Editable template', 'welcome', 'Hi {{client_name}}.');

        Sanctum::actingAs($admin);

        $response = $this->patchJson("/api/crm/settings/templates/{$template->id}", [
            'is_quick_reply' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('is_quick_reply', true);

        $this->assertDatabaseHas('templates', [
            'id' => $template->id,
            'is_quick_reply' => true,
        ]);
    }

    private function createTemplate(string $title, string $category, string $body, ?int $platformId = null): Template
    {
        return Template::create([
            'platform_id' => $platformId,
            'title' => $title,
            'category' => $category,
            'channel' => 'whatsapp',
            'subject' => null,
            'body' => $body,
            'variables' => [],
            'status' => 'active',
            'is_quick_reply' => true,
        ]);
    }

    private function createUser(string $role, array $platformIds = []): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $platformIds,
        ]);

        if ($platformIds !== []) {
            $user->platforms()->syncWithoutDetaching($platformIds);
        }

        return $user;
    }
}
