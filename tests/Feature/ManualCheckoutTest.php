<?php

namespace Tests\Feature;

use App\Models\BillingManualPaymentMethod;
use App\Models\BillingProxySession;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ManualCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private function makePayment(): Payment
    {
        $platform = Platform::factory()->create(['name' => 'Tanzania', 'country' => 'Tanzania', 'currency_code' => 'TZS']);
        $product = Product::factory()->create(['platform_id' => $platform->id, 'name' => 'VIP', 'display_name' => 'VIP']);
        $client = Client::factory()->create(['platform_id' => $platform->id, 'wp_user_id' => 4321, 'name' => 'Amani M']);

        BillingManualPaymentMethod::create([
            'market_id' => $platform->id,
            'method_key' => 'till',
            'enabled' => true,
            'display_name' => 'Vodacom M-Pesa',
            'instruction_intro' => 'Send to the number below.',
            'proof_required' => true,
            'sender_name_required' => true,
            'transaction_id_required' => true,
            'auto_activate_on_submission' => false,
            'details_json' => ['phone_number' => '+255746734025', 'recipient_name' => 'AMANI MOLEL'],
        ]);

        $price = \App\Models\ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_key' => '1_week',
            'duration_days' => 7,
            'price' => 15000,
            'currency' => 'TZS',
            'is_active' => true,
        ]);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'amount' => 15000,
            'currency' => 'TZS',
            'transaction_reference' => 'LIFECYCLE-99',
        ]);
        // Custom-priced lifecycle deal: real price lives in base_product_price_id.
        $deal = \App\Models\Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'product_price_id' => null,
            'base_product_price_id' => $price->id,
            'duration' => 'manual',
        ]);
        $payment->forceFill(['deal_id' => $deal->id])->save();

        return $payment->fresh();
    }

    public function test_signed_manual_checkout_page_renders_market_details(): void
    {
        $payment = $this->makePayment();
        $url = URL::temporarySignedRoute('manual.checkout', now()->addDay(), ['payment' => $payment->id]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Vodacom M-Pesa')
            ->assertSee('+255746734025')
            ->assertSee('TZS 15,000')
            ->assertSee('Upload your payment proof')
            ->assertSee("\u{1F1F9}\u{1F1FF}") // 🇹🇿 flag
            // Submit context locks to the catalog price (base_product_price_id),
            // so the submit resolves the currency price instead of 'manual'.
            ->assertSee('"product_price_id":' . $payment->deal->base_product_price_id, false);

        // The open is recorded so lifecycle analytics can count it.
        $this->assertDatabaseHas('billing_proxy_sessions', [
            'payment_id' => $payment->id,
            'provider_type_key' => 'manual_checkout',
        ]);
    }

    public function test_unsigned_manual_checkout_is_forbidden(): void
    {
        $payment = $this->makePayment();

        $this->get('/pay/manual/' . $payment->id)->assertForbidden();
    }
}
