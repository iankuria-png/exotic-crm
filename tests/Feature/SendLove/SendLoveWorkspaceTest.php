<?php

namespace Tests\Feature\SendLove;

use App\Models\Client;
use App\Models\LoveGift;
use App\Models\Platform;
use App\Models\SendLoveSetting;
use App\Models\User;
use App\Services\BillingGatewayService;
use App\Services\BillingModeService;
use App\Services\PaymentCompletionService;
use App\Services\SendLove\CheckoutService;
use App\Services\SendLove\SettingsService;
use App\Services\WalletSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class SendLoveWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Platform $p;

    private Client $c;

    private SendLoveSetting $s;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->mock(WalletSyncService::class, fn ($m) => $m->shouldReceive('syncClientBalanceById')->andReturn(['status' => 'skipped']));
        $this->mock(BillingModeService::class, function ($m) {
            $m->shouldReceive('assertWalletAvailable')->andReturn([]);
            $m->shouldReceive('providerContext')->andReturnUsing(function ($platform, $provider) {
                if ($provider === 'pawapay') {
                    throw new \InvalidArgumentException('Selected provider is disabled for this market.');
                }

                return ['environment' => 'production'];
            });
        });
        $this->mock(BillingGatewayService::class, fn ($m) => $m->shouldReceive('initiateSendLove')->andReturn(['action' => ['type' => 'stk_push']]));
        $this->p = Platform::factory()->create(['name' => 'Kenya', 'currency_code' => 'KES', 'phone_prefix' => '254']);
        $this->c = Client::factory()->create(['platform_id' => $this->p->id, 'wp_post_id' => 112541, 'wp_user_id' => 33167, 'profile_status' => 'publish', 'lifecycle_archived_at' => null, 'closed_at' => null, 'escort_expire' => now()->addMonth()->timestamp, 'is_high_risk' => false, 'wallet_currency' => 'KES']);
        User::firstOrCreate(['id' => 1], ['name' => 'QA', 'email' => 'qa@example.test', 'password' => 'qa', 'role' => 'admin', 'status' => 'active']);
        $this->s = app(SettingsService::class)->forPlatform($this->p);
        $this->s->update(['enabled' => true, 'rollout_mode' => 'live', 'allowed_providers_json' => ['kopokopo', 'pawapay']]);
    }

    private function gift(array $d = []): LoveGift
    {
        $input = array_merge(['wp_post_id' => $this->c->wp_post_id, 'session_proof' => str_repeat('a', 64), 'amount' => 1000, 'card_line' => 'Spoil yourself properly', 'visitor_phone' => '0712345678', 'provider_key' => 'kopokopo', 'message' => 'You made my week.', 'sender_name' => 'K.', 'share_contact' => false], $d);
        $ref = app(CheckoutService::class)->intent($this->p, $input, (string) Str::uuid(), Request::create('/', 'POST'))['gift_reference'];

        return LoveGift::where('public_id', $ref)->firstOrFail();
    }

    private function settle(LoveGift $g): void
    {
        app(PaymentCompletionService::class)->complete($g->payment, ['amount' => (float) $g->amount, 'currency' => 'KES']);
    }

    private function admin(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active']);
    }

    public function test_overview_separates_attempts_sent_notes_and_consent(): void
    {
        $this->settle($this->gift(['share_contact' => true]));
        $this->gift(['amount' => 500, 'card_line' => 'Spoil yourself', 'message' => null, 'visitor_phone' => '0722000111']);

        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/crm/monetization/love/overview?platform_id='.$this->p->id)
            ->assertOk()
            ->assertJsonPath('summary.attempts', 2)
            ->assertJsonPath('summary.sent', 1)
            ->assertJsonPath('summary.completion_rate', 50)
            ->assertJsonPath('summary.notes', 1)
            ->assertJsonPath('summary.contact_shared', 1)
            ->assertJsonPath('summary.senders', 1)
            ->assertJsonPath('totals.0.currency', 'KES')
            ->assertJsonPath('trend_window', 'last_30_days')
            ->assertJsonCount(1, 'trend')
            ->assertJsonPath('amounts.0.gifts', 1)
            ->assertJsonPath('top_creators.0.client.id', $this->c->id)
            ->assertJsonPath('markets.0.id', $this->p->id);
    }

    public function test_notes_are_manager_only_filterable_and_never_expose_numbers(): void
    {
        $reported = $this->gift(['share_contact' => true]);
        $this->settle($reported);
        $r = Request::create('/', 'POST', ['wp_post_id' => $this->c->wp_post_id, 'wp_user_id' => $this->c->wp_user_id]);
        $r->attributes->set('wallet_platform', $this->p);
        (new \App\Http\Controllers\Wp\SendLoveController)->report($r, $reported->public_id);
        $this->settle($this->gift(['message' => 'Thank you for tonight', 'visitor_phone' => '0722000111']));

        $this->actingAs($this->admin('sales'), 'sanctum')->getJson('/api/crm/monetization/love/notes')->assertForbidden();

        $response = $this->actingAs($this->admin(), 'sanctum')->getJson('/api/crm/monetization/love/notes?platform_id='.$this->p->id.'&state=reported')
            ->assertOk()
            ->assertJsonPath('counts.all', 2)
            ->assertJsonPath('counts.reported', 1)
            ->assertJsonPath('counts.hidden_by_creator', 1)
            ->assertJsonCount(1, 'notes.data')
            ->assertJsonPath('notes.data.0.public_id', $reported->public_id)
            ->assertJsonPath('notes.data.0.client.id', $this->c->id);
        $body = $response->getContent();
        $this->assertStringNotContainsString('contact_phone', $body);
        $this->assertStringNotContainsString('visitor_phone_hash', $body);
        $this->assertStringNotContainsString('0712345678', $body);
    }

    public function test_markets_report_provider_readiness_and_wordpress_sync(): void
    {
        $this->actingAs($this->admin(), 'sanctum')->getJson('/api/crm/monetization/love/markets')
            ->assertOk()
            ->assertJsonPath('can_manage', true)
            ->assertJsonPath('global_paused', false)
            ->assertJsonPath('markets.0.platform.name', 'Kenya')
            ->assertJsonPath('markets.0.rollout_mode', 'live')
            ->assertJsonPath('markets.0.in_sync', false)
            ->assertJsonPath('markets.0.providers.0.key', 'kopokopo')
            ->assertJsonPath('markets.0.providers.0.ready', true)
            ->assertJsonPath('markets.0.providers.1.ready', false)
            ->assertJsonPath('markets.0.providers.1.message', 'Selected provider is disabled for this market.');
    }

    public function test_sales_role_without_market_access_sees_no_markets(): void
    {
        $sales = $this->admin('sales');
        $ids = app(\App\Services\MarketAuthorizationService::class)->resolveAccessiblePlatformIds($sales);
        $response = $this->actingAs($sales, 'sanctum')->getJson('/api/crm/monetization/love/markets')->assertOk()->assertJsonPath('can_manage', false);
        if ($ids !== null) {
            $this->assertSame(in_array($this->p->id, $ids, true) ? 1 : 0, count($response->json('markets')));
        }
    }
}
