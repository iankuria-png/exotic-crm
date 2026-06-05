<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Template;
use App\Models\User;
use App\Services\ClientOutreachService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientOutreachServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClientOutreachService $service;
    private Platform $platform;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ClientOutreachService::class);
        $this->platform = Platform::factory()->create([
            'name' => 'Kenya',
            'phone_prefix' => '254',
        ]);
        $this->user = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
        ]);
        $this->user->platforms()->sync([$this->platform->id]);

        $this->seedQuickReplyTemplates();
    }

    public function test_latest_expired_deal_suggests_win_back_even_when_client_has_older_active_deal(): void
    {
        $client = Client::factory()->create(['platform_id' => $this->platform->id]);

        Deal::factory()->create([
            'platform_id' => $this->platform->id,
            'client_id' => $client->id,
            'status' => 'active',
            'expires_at' => now()->addDays(30),
            'created_at' => now()->subDays(20),
        ]);
        Deal::factory()->create([
            'platform_id' => $this->platform->id,
            'client_id' => $client->id,
            'status' => 'expired',
            'expires_at' => now()->subDays(4),
            'created_at' => now()->subDay(),
        ]);

        $payload = $this->service->quickRepliesFor($client, $this->user);

        $this->assertSame('expired', $payload['situation']);
        $this->assertSame('win_back', $this->suggestedCategory($payload));
    }

    public function test_legacy_expiry_uses_latest_valid_timestamp_for_virtual_deal(): void
    {
        $client = Client::factory()->create([
            'platform_id' => $this->platform->id,
            'escort_expire' => now()->subDays(20)->timestamp,
            'premium_expire' => now()->addDays(3)->timestamp,
            'featured_expire' => null,
        ]);

        $payload = $this->service->quickRepliesFor($client, $this->user);

        $this->assertSame('expiring', $payload['situation']);
        $this->assertSame('renewal', $this->suggestedCategory($payload));
    }

    public function test_never_paid_suggests_payment_and_filters_expiry_dependent_copy(): void
    {
        $client = Client::factory()->create([
            'platform_id' => $this->platform->id,
            'created_at' => now()->subDay(),
        ]);

        $payload = $this->service->quickRepliesFor($client, $this->user);
        $categories = collect($payload['messages'])->pluck('category')->all();

        $this->assertSame('never_paid', $payload['situation']);
        $this->assertSame('payment', $this->suggestedCategory($payload));
        $this->assertNotContains('win_back', $categories);
        $this->assertContains('welcome', $categories);
    }

    public function test_completed_payment_prevents_never_paid_classification(): void
    {
        $client = Client::factory()->create(['platform_id' => $this->platform->id]);
        Payment::factory()->create([
            'platform_id' => $this->platform->id,
            'client_id' => $client->id,
            'status' => 'completed',
        ]);

        $payload = $this->service->quickRepliesFor($client, $this->user);

        $this->assertSame('active', $payload['situation']);
        $this->assertSame('welcome', $this->suggestedCategory($payload));
    }

    private function suggestedCategory(array $payload): ?string
    {
        return collect($payload['messages'])
            ->firstWhere('suggested', true)['category'] ?? null;
    }

    private function seedQuickReplyTemplates(): void
    {
        foreach ([
            ['Win-back', 'win_back', 'Hey {{client_name}}, expired {{days_since_expiry}} days ago.'],
            ['Renewal', 'renewal', 'Hi {{client_name}}, expires in {{days_left}} days on {{expiry_date}}.'],
            ['Payment', 'payment', 'Hi {{client_name}}, please activate your profile.'],
            ['Welcome', 'welcome', 'Hi {{client_name}} from {{platform_name}}.'],
        ] as [$title, $category, $body]) {
            Template::create([
                'title' => $title,
                'category' => $category,
                'channel' => 'whatsapp',
                'subject' => null,
                'body' => $body,
                'status' => 'active',
                'variables' => [],
                'is_quick_reply' => true,
            ]);
        }
    }
}
