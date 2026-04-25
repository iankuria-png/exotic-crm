<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentFailureAlertsJob;
use App\Models\Client;
use App\Models\Platform;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentFailureSmsAlertsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sms.enabled' => true,
            'services.sms.active_provider' => 'legacy_gateway',
            'services.sms.fallback_provider' => 'none',
            'services.sms.default_prefix' => '254',
            'services.sms.gateway_url' => 'https://sms-gateway.example.test/send',
            'services.sms.org_code' => '76',
        ]);
    }

    public function test_payment_status_transition_to_failed_dispatches_alert_job_after_commit(): void
    {
        Queue::fake();

        $platform = $this->createPlatform();
        $payment = $this->createPayment($platform, ['status' => 'initiated']);

        $payment->forceFill([
            'status' => 'failed',
            'failure_reason' => 'Provider timeout',
        ])->save();

        Queue::assertPushed(SendPaymentFailureAlertsJob::class, function (SendPaymentFailureAlertsJob $job) use ($payment): bool {
            return $job->paymentId === (int) $payment->id;
        });
    }

    public function test_failed_payment_alert_job_resolves_sales_sub_admin_and_global_admin_recipients(): void
    {
        Http::fake([
            '*' => Http::response('OK', 200),
        ]);

        $platform = $this->createPlatform(['phone_prefix' => '233']);
        $otherPlatform = $this->createPlatform([
            'name' => 'Other Market',
            'slug' => 'other-market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
        ]);

        $payment = $this->createPayment($platform, [
            'status' => 'failed',
            'failure_reason' => 'Provider timeout',
            'currency' => 'GHS',
            'amount' => 120.5,
        ]);

        User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'phone' => '020 123 4567',
            'assigned_market_ids' => [$platform->id],
        ]);

        $subAdmin = User::factory()->create([
            'role' => 'sub_admin',
            'status' => 'active',
            'phone' => '0201234568',
            'assigned_market_ids' => [$platform->id],
            'notification_prefs' => [
                'payment_failure_sms' => [
                    'enabled' => true,
                    'market_ids' => [$platform->id],
                ],
            ],
        ]);

        $globalAdmin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'phone' => '0201234569',
            'assigned_market_ids' => [],
            'notification_prefs' => [
                'payment_failure_sms' => [
                    'enabled' => true,
                    'market_ids' => null,
                ],
            ],
        ]);

        $globalAdmin->platforms()->sync([$otherPlatform->id]);

        User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'phone' => '0201234570',
            'assigned_market_ids' => [$otherPlatform->id],
        ]);

        User::factory()->create([
            'role' => 'sub_admin',
            'status' => 'active',
            'phone' => '0201234571',
            'assigned_market_ids' => [$platform->id],
            'notification_prefs' => [
                'payment_failure_sms' => [
                    'enabled' => true,
                    'market_ids' => [$otherPlatform->id],
                ],
            ],
        ]);

        User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'phone' => '0201234572',
            'assigned_market_ids' => [],
        ]);

        (new SendPaymentFailureAlertsJob((int) $payment->id))->handle(
            app(\App\Services\NotificationService::class),
            app(\App\Services\MarketAuthorizationService::class)
        );

        Http::assertSentCount(3);
        Http::assertSent(function ($request): bool {
            return $request['Phonenumber'] === '233201234567';
        });
        Http::assertSent(function ($request): bool {
            return $request['Phonenumber'] === '233201234568';
        });
        Http::assertSent(function ($request): bool {
            return $request['Phonenumber'] === '233201234569';
        });
    }

    public function test_alert_job_skips_test_payments(): void
    {
        Http::fake([
            '*' => Http::response('OK', 200),
        ]);

        $platform = $this->createPlatform();
        $payment = $this->createPayment($platform, [
            'status' => 'failed',
            'provider_environment' => 'sandbox',
        ]);

        User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'phone' => '0712345678',
            'assigned_market_ids' => [$platform->id],
        ]);

        (new SendPaymentFailureAlertsJob((int) $payment->id))->handle(
            app(\App\Services\NotificationService::class),
            app(\App\Services\MarketAuthorizationService::class)
        );

        Http::assertNothingSent();
    }

    public function test_admin_can_store_and_update_phone_and_notification_preferences_on_roles_endpoints(): void
    {
        $admin = $this->createUser('admin');
        $platform = $this->createPlatform();

        Sanctum::actingAs($admin);

        $createResponse = $this->postJson('/api/crm/settings/roles/users', [
            'name' => 'Alerts User',
            'email' => 'alerts@example.test',
            'role' => 'sales',
            'status' => 'active',
            'phone' => '0712345678',
            'assigned_market_ids' => [$platform->id],
            'notification_prefs' => [
                'payment_failure_sms' => [
                    'enabled' => true,
                    'market_ids' => null,
                ],
            ],
            'reason' => 'Create alert recipient',
        ]);

        $createResponse->assertCreated()
            ->assertJsonPath('phone', '0712345678')
            ->assertJsonPath('notification_prefs.payment_failure_sms.enabled', true);

        $userId = (int) $createResponse->json('id');

        $updateResponse = $this->patchJson("/api/crm/settings/roles/{$userId}", [
            'role' => 'sub_admin',
            'status' => 'active',
            'phone' => '0722000111',
            'assigned_market_ids' => [$platform->id],
            'notification_prefs' => [
                'payment_failure_sms' => [
                    'enabled' => true,
                    'market_ids' => [$platform->id],
                ],
            ],
            'reason' => 'Enable scoped payment alerts',
        ]);

        $updateResponse->assertOk()
            ->assertJsonPath('phone', '0722000111')
            ->assertJsonPath('notification_prefs.payment_failure_sms.market_ids.0', $platform->id);

        $rolesResponse = $this->getJson('/api/crm/settings/roles');

        $rolesResponse->assertOk();
        $users = collect($rolesResponse->json('users'));
        $saved = $users->firstWhere('id', $userId);

        $this->assertNotNull($saved);
        $this->assertSame('0722000111', data_get($saved, 'phone'));
        $this->assertSame([$platform->id], data_get($saved, 'notification_prefs.payment_failure_sms.market_ids'));
    }

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'assigned_market_ids' => $assignedMarketIds,
            'status' => 'active',
        ]);
    }

    private function createPlatform(array $overrides = []): Platform
    {
        static $sequence = 1;

        $index = $sequence++;

        return Platform::query()->create(array_merge([
            'name' => 'Market ' . $index,
            'domain' => 'market-' . $index . '.example.test',
            'slug' => 'market-' . $index,
            'country' => 'Ghana',
            'currency_code' => 'GHS',
            'phone_prefix' => '233',
            'timezone' => 'Africa/Accra',
            'payment_instruction' => 'Pay via mobile money',
        ], $overrides));
    }

    private function createPayment(Platform $platform, array $overrides = []): Payment
    {
        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => random_int(1000, 9999),
            'name' => 'Client Example',
            'phone_normalized' => '233201111111',
        ]);

        $product = Product::query()->create([
            'name' => 'VIP Boost',
            'display_name' => 'VIP Boost',
            'slug' => 'vip-boost-' . random_int(1000, 9999),
            'platform_id' => $platform->id,
            'tier' => 'vip',
            'monthly_price' => 50,
            'biweekly_price' => 30,
            'weekly_price' => 20,
            'currency' => $platform->currency_code ?? 'GHS',
            'is_active' => true,
            'is_archived' => false,
            'sort_order' => 10,
        ]);

        return Payment::query()->create(array_merge([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'phone' => '233201111111',
            'amount' => 99.99,
            'currency' => $platform->currency_code ?? 'GHS',
            'transaction_reference' => 'TXN-' . random_int(100000, 999999),
            'reference_number' => 'REF-' . random_int(100000, 999999),
            'status' => 'initiated',
            'source' => 'gateway',
            'payment_data' => [],
            'raw_payload' => [],
        ], $overrides));
    }
}
