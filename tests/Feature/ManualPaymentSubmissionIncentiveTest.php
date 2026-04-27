<?php

namespace Tests\Feature;

use App\Models\BillingManualPaymentMethod;
use App\Models\BillingSubscriptionRule;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Services\SubscriptionProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ManualPaymentSubmissionIncentiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_payment_submission_applies_incentive_and_provisions_discounted_deal_metadata(): void
    {
        Storage::fake('local');

        [
            'platform' => $platform,
            'product' => $product,
            'client' => $client,
        ] = $this->seedContext();

        BillingSubscriptionRule::query()->create([
            'market_id' => $platform->id,
            'activation_method_json' => ['methods' => ['manual', 'payment_link']],
            'renewal_method_json' => ['methods' => ['wallet_balance', 'payment_link']],
            'free_trial_json' => ['enabled' => false],
            'discount_json' => [
                'enabled' => true,
                'self_service_incentive' => [
                    'enabled' => true,
                    'percent' => 10,
                    'label' => 'Manual special',
                    'starts_at' => now()->subMinute()->toIso8601String(),
                    'expires_at' => now()->addDay()->toIso8601String(),
                    'sources' => ['wallet', 'self_checkout', 'manual_submission'],
                ],
            ],
            'expiry_policy_json' => ['grace_period_days' => 7],
        ]);

        BillingManualPaymentMethod::query()->create([
            'market_id' => $platform->id,
            'method_key' => 'mpesa',
            'enabled' => true,
            'display_name' => 'M-PESA',
            'instruction_intro' => 'Pay with M-PESA and upload proof.',
            'instruction_footer' => '',
            'proof_required' => true,
            'sender_name_required' => true,
            'transaction_id_required' => true,
            'auto_activate_on_submission' => false,
            'details_json' => ['paybill' => '123456'],
        ]);

        $response = $this->post('/api/manual-payment-submissions', [
            'product_id' => $product->id,
            'platform_id' => $platform->id,
            'user_id' => $client->wp_user_id,
            'first_name' => 'Jane',
            'last_name' => 'Escort',
            'phone' => $client->phone_normalized,
            'email' => $client->email,
            'duration' => 'monthly',
            'manual_method_key' => 'mpesa',
            'sender_name' => 'Jane Sender',
            'transaction_reference' => 'MPESA-REF-001',
            'customer_note' => 'Paid just now.',
            'proof_image' => UploadedFile::fake()->image('proof.png'),
        ], [
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', true);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(2160.0, (float) $payment->amount);
        $this->assertSame(2400.0, (float) data_get($payment->payment_data, 'self_service_incentive.original_amount'));
        $this->assertSame(10.0, (float) data_get($payment->payment_data, 'self_service_incentive.percent'));
        $this->assertSame('self_service_incentive', data_get($payment->payment_data, 'self_service_incentive.source'));

        Http::fake($this->provisioningApiFakes($platform, $client));

        $payment->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
        ])->save();

        $deal = app(SubscriptionProvisioningService::class)->provisionCompletedPayment($payment->fresh([
            'client.platform',
            'platform',
            'product',
        ]), [
            'client' => $client->fresh(['platform']),
            'confirmed_at' => now(),
            'match_confidence' => 'manual',
            'reconciliation_confidence' => 'high',
            'reconciliation_state' => 'resolved',
            'payment_method' => 'manual',
            'duration_days' => 30,
            'payment_reference' => $payment->reference_number,
            'emit_payment_received_timeline' => true,
            'emit_profile_activated_timeline' => false,
            'emit_deal_activated_timeline' => true,
        ]);

        $this->assertSame(2400.0, (float) $deal->original_amount);
        $this->assertSame(10.0, (float) $deal->discount_percentage);
        $this->assertSame('self_service_incentive', $deal->discount_source);
        $this->assertNull($deal->discount_approved_by);
    }

    /**
     * @return array{platform:Platform,product:Product,client:Client}
     */
    private function seedContext(): array
    {
        $platform = Platform::factory()->create([
            'name' => 'Kenya Market',
            'country' => 'Kenya',
            'domain' => 'manual-payments.example.test',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://manual-payments.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-sync-user',
            'wp_api_password' => 'crm-sync-secret',
        ]);

        $product = Product::factory()->create([
            'platform_id' => $platform->id,
            'name' => 'Premium Escort',
            'display_name' => 'Premium Escort',
            'slug' => 'premium-escort',
            'tier' => 'premium',
            'weekly_price' => 600,
            'biweekly_price' => 1300,
            'monthly_price' => 2400,
            'currency' => 'KES',
            'is_active' => true,
            'is_archived' => false,
        ]);

        ProductPrice::factory()->create([
            'product_id' => $product->id,
            'duration_key' => '1_month',
            'duration_label' => '1 Month',
            'duration_days' => 30,
            'price' => 2400,
            'currency' => 'KES',
            'is_active' => true,
            'sort_order' => 30,
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 4401,
            'wp_user_id' => 8801,
            'name' => 'Jane Escort',
            'phone_normalized' => '254700000111',
            'email' => 'jane@example.test',
            'profile_status' => 'publish',
        ]);

        return [
            'platform' => $platform,
            'product' => $product,
            'client' => $client,
        ];
    }

    /**
     * @return array<string, \Closure|\Illuminate\Http\Client\Response>
     */
    private function provisioningApiFakes(Platform $platform, Client $client): array
    {
        return [
            $platform->wp_api_url . '/clients/' . $client->wp_post_id . '/activate' => Http::response([
                'ok' => true,
                'post_id' => $client->wp_post_id,
            ], 200),
            $platform->wp_api_url . '/clients/' . $client->wp_post_id => Http::response([
                'wp_post_id' => $client->wp_post_id,
                'wp_user_id' => $client->wp_user_id,
                'name' => $client->name,
                'phone' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'post_status' => 'publish',
                'premium' => true,
                'premium_expire' => now()->addDays(30)->timestamp,
                'featured' => false,
                'featured_expire' => null,
                'escort_expire' => now()->addDays(30)->timestamp,
                'verified' => true,
                'last_online' => now()->timestamp,
                'main_image_url' => 'https://images.example.test/profile.jpg',
            ], 200),
        ];
    }
}
