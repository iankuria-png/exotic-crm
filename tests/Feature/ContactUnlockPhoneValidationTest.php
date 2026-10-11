<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContactUnlockPricingRule;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\BillingGatewayService;
use App\Services\ContactUnlockCheckoutService;
use App\Services\ContactUnlockUpgradeQuoteService;
use App\Services\FeatureSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ContactUnlockPhoneValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_kenya_lengths_fail_before_payment_or_provider_request(): void
    {
        $market = Platform::factory()->create(['phone_prefix' => '254', 'country' => 'Kenya', 'currency_code' => 'KES']);
        $client = Client::factory()->create(['platform_id' => $market->id, 'wp_post_id' => 123, 'lifecycle_state' => 'expired']);
        $settings = app(FeatureSettingsService::class);
        $settings->set('contact_unlock.enabled', true);
        $settings->set('contact_unlock.market_ids', [$market->id]);
        $rule = ContactUnlockPricingRule::create(['platform_id' => $market->id, 'scope' => 'single_profile', 'label' => 'Unlock', 'currency' => 'KES', 'amount' => 450, 'duration_days' => 5, 'is_active' => true]);
        $gateway = Mockery::mock(BillingGatewayService::class);
        $gateway->shouldNotReceive('initiateContactUnlock');
        $this->app->instance(BillingGatewayService::class, $gateway);
        $this->app->instance(ContactUnlockUpgradeQuoteService::class, Mockery::mock(ContactUnlockUpgradeQuoteService::class));
        Http::preventStrayRequests();
        foreach (['25471234567', '2547123456789'] as $phone) {
            try {
                app(ContactUnlockCheckoutService::class)->createIntent($market, 123, $rule->id, 'kopokopo', $phone, null, 'test-proof', 'test-idempotency');
                $this->fail('Invalid phone accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('visitor_phone', $e->errors());
            }
        }
        $this->assertSame(0, Payment::count());
        Http::assertNothingSent();
    }
}
