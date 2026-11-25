<?php

namespace Tests\Support;

use App\Models\BillingMarketProviderBinding;
use App\Models\BillingProviderProfile;
use App\Models\BillingSubscriptionRule;
use App\Models\Client;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\FlexibleAmounts\FlexibleQuoteService;
use App\Services\FlexibleAmounts\FlexibleRulesService;
use App\Services\WpSyncFactory;
use App\Services\WpSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait FlexibleAmountsFixture
{
    protected $dropViews = true;

    protected Platform $market;

    protected Client $client;

    protected Product $product;

    protected array $state;

    protected $wp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Http::preventStrayRequests();
        $this->market = Platform::factory()->create(['domain' => 'https://flexible.test', 'country' => 'Kenya', 'currency_code' => 'KES', 'phone_prefix' => '254', 'timezone' => 'Africa/Nairobi', 'is_active' => true,
            'payment_link_providers' => ['active_provider' => 'pawapay_checkout', 'providers' => ['pawapay_checkout' => ['enabled' => true, 'mode' => 'proxy_hosted_checkout', 'wallet_provider_key' => 'pawapay', 'environment' => 'sandbox']]]]);
        $this->client = Client::factory()->create(['platform_id' => $this->market->id, 'phone_normalized' => '254712345678', 'escort_expire' => now()->addDays(6)->timestamp]);
        $this->product = Product::factory()->create(['platform_id' => $this->market->id, 'name' => 'VIP', 'tier' => 'vip', 'is_active' => true, 'is_public' => true, 'is_archived' => false, 'currency' => 'KES']);
        foreach ([7 => 1500, 14 => 3000, 30 => 6000, 3 => 500] as $d => $price) {
            ProductPrice::create(['product_id' => $this->product->id, 'duration_key' => $d.'_days', 'duration_label' => $d.' days', 'duration_days' => $d, 'price' => $price, 'currency' => 'KES', 'is_active' => true]);
        }
        $rules = app(FlexibleRulesService::class)->defaults($this->market);
        $rules = array_replace($rules, ['mode' => 'both', 'rules_version' => 1, 'rescue_enabled' => true, 'surfaces' => ['dashboard' => true, 'pay_link' => true, 'crm_link' => true]]);
        BillingSubscriptionRule::create(['market_id' => $this->market->id, 'flexible_amounts' => $rules]);
        $profile = BillingProviderProfile::create(['provider_type_key' => 'pawapay', 'profile_name' => 'Isolated sandbox', 'market_id' => $this->market->id, 'country_code' => 'KE', 'environment' => 'sandbox', 'active' => true, 'config_json' => [], 'secrets_json' => ['api_key' => 'isolated-fixture']]);
        foreach (['self_checkout', 'proxy_hosted_checkout'] as $surface) {
            BillingMarketProviderBinding::create(['market_id' => $this->market->id, 'provider_profile_id' => $profile->id, 'billing_surface' => $surface, 'enabled' => true, 'self_service_enabled' => true, 'execution_mode' => 'proxy', 'priority' => 1]);
        }
        $this->state = ['version' => 1, 'writers_ready' => true, 'eligible' => true, 'post_id' => $this->client->wp_post_id, 'wp_user_id' => $this->client->wp_user_id, 'live' => true, 'current_tier' => 'vip', 'escort_expire' => $this->client->escort_expire];
        $this->wp = \Mockery::mock(WpSyncService::class);
        $this->wp->shouldReceive('payLinkState')->andReturnUsing(fn () => $this->state);
        $this->wp->shouldReceive('payLinkStateForRead')->andReturnUsing(fn () => $this->state);
        $this->mock(WpSyncFactory::class)->shouldReceive('forPlatform')->andReturn($this->wp);
    }

    protected function quote(array $extra = []): array
    {
        return app(FlexibleQuoteService::class)->quote($this->market, $this->client, $extra + ['product_id' => $this->product->id, 'amount' => '1000']);
    }

    protected function request(array $extra = [], ?string $key = null): Request
    {
        $quote = $this->quote($extra);
        $r = Request::create('/api/wp-svc/flexible-amounts/checkout', 'POST', $extra + ['wp_user_id' => $this->client->wp_user_id, 'authenticated_customer' => true, 'product_id' => $this->product->id, 'amount' => '1000', 'rules_version' => $quote['rules_version'], 'catalogue_hash' => $quote['catalogue_hash'], 'confirmation_hash' => $quote['confirmation_hash']]);
        $r->attributes->set('wallet_platform', $this->market);
        $r->headers->set('X-Idempotency-Key', $key ?? (string) Str::uuid());

        return $r;
    }
}
