<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentFailureAlertRecipientJob;
use App\Jobs\SendPaymentFailureAlertsJob;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Services\NotificationService;
use App\Services\PaymentAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_payment_status_transition_to_failed_dispatches_coordinator_job_on_alerts_queue(): void
    {
        Queue::fake();

        $platform = $this->createPlatform();
        $payment = $this->createPayment($platform, ['status' => 'initiated']);

        $payment->forceFill([
            'status' => 'failed',
            'failure_reason' => 'Provider timeout',
        ])->save();

        $payment->refresh();

        Queue::assertPushed(SendPaymentFailureAlertsJob::class, function (SendPaymentFailureAlertsJob $job) use ($payment): bool {
            return $job->paymentId === (int) $payment->id
                && $job->eventKey === $payment->paymentFailureAlertEventKey()
                && $job->queue === 'alerts';
        });
    }

    public function test_coordinator_job_snapshots_recipients_and_dispatches_one_alert_job_per_recipient(): void
    {
        Queue::fake();

        $platform = $this->createPlatform(['phone_prefix' => '233']);
        $otherPlatform = $this->createPlatform([
            'name' => 'Other Market',
            'slug' => 'other-market',
            'country' => 'Kenya',
            'phone_prefix' => '254',
        ]);

        $payment = $this->createFailedPaymentWithAlertEvent($platform);

        User::factory()->create([
            'name' => 'Sales One',
            'role' => 'sales',
            'status' => 'active',
            'phone' => '0201234567',
            'assigned_market_ids' => [$platform->id],
        ]);

        User::factory()->create([
            'name' => 'Sub Admin One',
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
            'name' => 'Global Admin',
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
            'role' => 'admin',
            'status' => 'active',
            'phone' => '0201234570',
            'notification_prefs' => [
                'payment_failure_sms' => [
                    'enabled' => false,
                    'market_ids' => null,
                ],
            ],
        ]);

        $job = new SendPaymentFailureAlertsJob(
            (int) $payment->id,
            $payment->paymentFailureAlertEventKey(),
            'payment_model_saved'
        );

        $job->handle(
            app(PaymentAttemptService::class),
            app(MarketAuthorizationService::class)
        );

        Queue::assertPushed(SendPaymentFailureAlertRecipientJob::class, 3);
        Queue::assertPushed(SendPaymentFailureAlertRecipientJob::class, function (SendPaymentFailureAlertRecipientJob $job): bool {
            return (int) ($job->recipient['id'] ?? 0) > 0 && $job->queue === 'alerts';
        });

        $attempt = PaymentAttempt::query()
            ->where('payment_id', (int) $payment->id)
            ->where('attempt_type', 'payment_failure_alert_enqueue')
            ->latest('id')
            ->first();

        $this->assertNotNull($attempt);
        $this->assertSame('queued', $attempt->status);
        $this->assertSame(3, data_get($attempt->response_meta, 'recipient_count'));
    }

    public function test_admin_with_phone_but_alerts_disabled_is_skipped_and_no_recipient_jobs_are_queued(): void
    {
        Queue::fake();

        $platform = $this->createPlatform();
        $payment = $this->createFailedPaymentWithAlertEvent($platform);

        User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'phone' => '0712345678',
            'assigned_market_ids' => [],
        ]);

        (new SendPaymentFailureAlertsJob(
            (int) $payment->id,
            $payment->paymentFailureAlertEventKey(),
            'payment_model_saved'
        ))->handle(
            app(PaymentAttemptService::class),
            app(MarketAuthorizationService::class)
        );

        Queue::assertNotPushed(SendPaymentFailureAlertRecipientJob::class);

        $attempt = PaymentAttempt::query()
            ->where('payment_id', (int) $payment->id)
            ->where('attempt_type', 'payment_failure_alert_enqueue')
            ->latest('id')
            ->first();

        $this->assertNotNull($attempt);
        $this->assertSame('skipped', $attempt->status);
        $this->assertSame('no_recipients', $attempt->error_code);
    }

    public function test_recipient_job_records_sent_attempt_and_will_not_resend_same_event_to_same_user(): void
    {
        Http::fake([
            '*' => Http::response('OK', 200),
        ]);

        $platform = $this->createPlatform(['phone_prefix' => '233']);
        $payment = $this->createFailedPaymentWithAlertEvent($platform, [
            'currency' => 'GHS',
            'amount' => 120.5,
            'failure_reason' => 'Provider timeout',
        ]);

        $recipient = [
            'id' => 99,
            'name' => 'Sales One',
            'role' => 'sales',
            'phone' => '020 123 4567',
        ];

        $job = new SendPaymentFailureAlertRecipientJob(
            (int) $payment->id,
            $payment->paymentFailureAlertEventKey(),
            $recipient,
            'payment_failure_alert_enqueue'
        );

        $job->handle(
            app(NotificationService::class),
            app(PaymentAttemptService::class)
        );
        $job->handle(
            app(NotificationService::class),
            app(PaymentAttemptService::class)
        );

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request['Phonenumber'] === '233201234567';
        });

        $attempts = PaymentAttempt::query()
            ->where('payment_id', (int) $payment->id)
            ->where('attempt_type', 'payment_failure_alert_sms')
            ->get();

        $this->assertCount(1, $attempts);
        $this->assertSame('sent', $attempts->first()->status);
    }

    public function test_coordinator_job_skips_test_payments_and_records_skipped_attempt(): void
    {
        Queue::fake();

        $platform = $this->createPlatform();
        $payment = $this->createFailedPaymentWithAlertEvent($platform, [
            'provider_environment' => 'sandbox',
        ]);

        User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'phone' => '0712345678',
            'assigned_market_ids' => [$platform->id],
        ]);

        (new SendPaymentFailureAlertsJob(
            (int) $payment->id,
            $payment->paymentFailureAlertEventKey(),
            'payment_model_saved'
        ))->handle(
            app(PaymentAttemptService::class),
            app(MarketAuthorizationService::class)
        );

        Queue::assertNotPushed(SendPaymentFailureAlertRecipientJob::class);

        $attempt = PaymentAttempt::query()
            ->where('payment_id', (int) $payment->id)
            ->where('attempt_type', 'payment_failure_alert_enqueue')
            ->latest('id')
            ->first();

        $this->assertNotNull($attempt);
        $this->assertSame('skipped', $attempt->status);
        $this->assertSame('test_payment', $attempt->error_code);
    }

    public function test_watchdog_command_backfills_missing_failed_payment_alert_enqueue_job(): void
    {
        Queue::fake();

        $platform = $this->createPlatform();
        $payment = Payment::withoutEvents(function () use ($platform): Payment {
            return $this->createPayment($platform, [
                'status' => 'failed',
                'failure_reason' => 'Webhook marked as failed',
                'raw_payload' => [],
            ]);
        });

        DB::table('payments')
            ->where('id', (int) $payment->id)
            ->update([
                'raw_payload' => json_encode([
                    'payment_failure_alert' => [
                        'event_key' => Payment::buildPaymentFailureAlertEventKey((int) $payment->id, $payment->updated_at),
                        'status_changed_at' => $payment->updated_at?->toIso8601String(),
                    ],
                ], JSON_UNESCAPED_SLASHES),
            ]);

        $this->artisan('crm:reconcile-payment-failure-alerts')
            ->expectsOutputToContain('queued,')
            ->assertSuccessful();

        Queue::assertPushed(SendPaymentFailureAlertsJob::class, function (SendPaymentFailureAlertsJob $job) use ($payment): bool {
            return $job->paymentId === (int) $payment->id
                && $job->eventKey === $payment->fresh()->paymentFailureAlertEventKey()
                && $job->triggerSource === 'reconcile_payment_failure_alerts';
        });
    }

    public function test_admin_can_store_and_update_phone_notification_preferences_and_live_alert_state_on_roles_endpoints(): void
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
            ->assertJsonPath('notification_prefs.payment_failure_sms.enabled', true)
            ->assertJsonPath('payment_failure_sms_state', 'enabled');

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
            ->assertJsonPath('notification_prefs.payment_failure_sms.market_ids.0', $platform->id)
            ->assertJsonPath('payment_failure_sms_state', 'enabled');

        $rolesResponse = $this->getJson('/api/crm/settings/roles');

        $rolesResponse->assertOk();
        $users = collect($rolesResponse->json('users'));
        $saved = $users->firstWhere('id', $userId);

        $this->assertNotNull($saved);
        $this->assertSame('0722000111', data_get($saved, 'phone'));
        $this->assertSame([$platform->id], data_get($saved, 'notification_prefs.payment_failure_sms.market_ids'));
        $this->assertSame('enabled', data_get($saved, 'payment_failure_sms_state'));
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

    private function createFailedPaymentWithAlertEvent(Platform $platform, array $overrides = []): Payment
    {
        $payment = Payment::withoutEvents(function () use ($platform, $overrides): Payment {
            return $this->createPayment($platform, array_merge([
                'status' => 'failed',
                'failure_reason' => 'Provider timeout',
                'raw_payload' => [],
            ], $overrides));
        });

        $rawPayload = is_array($payment->raw_payload) ? $payment->raw_payload : [];
        $rawPayload['payment_failure_alert'] = [
            'event_key' => Payment::buildPaymentFailureAlertEventKey((int) $payment->id, $payment->updated_at),
            'status_changed_at' => $payment->updated_at?->toIso8601String(),
        ];

        DB::table('payments')
            ->where('id', (int) $payment->id)
            ->update([
                'raw_payload' => json_encode($rawPayload, JSON_UNESCAPED_SLASHES),
            ]);

        return $payment->fresh(['platform', 'client', 'product']);
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
