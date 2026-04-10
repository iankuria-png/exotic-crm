<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ManualPaymentBundle;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentQueueSandboxVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_workspace_hides_test_and_sandbox_rows_from_table_and_summary_cards(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform, 'sales');

        $this->createPayment($platform, [
            'transaction_reference' => 'LIVE-PAYMENT-001',
            'amount' => 5000,
            'status' => 'completed',
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'LIVE-MANUAL-REVIEW-001',
            'amount' => 4100,
            'status' => 'completed',
            'reconciliation_state' => 'manual_review',
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'SANDBOX-PAYMENT-001',
            'amount' => 9000,
            'status' => 'completed',
            'provider_environment' => 'sandbox',
            'payment_data' => [
                'test_mode' => true,
                'test_result' => 'completed',
            ],
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'EXPLICIT-TEST-001',
            'amount' => 3000,
            'status' => 'completed',
            'record_classification' => Payment::RECORD_CLASSIFICATION_TEST,
            'test_reason' => 'QA verification row',
            'test_marked_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('can_view_tests', false)
            ->assertJsonPath('test_visibility', 'hide')
            ->assertJsonPath('stats_scope', 'business')
            ->assertJsonPath('stats.confirmed', 1)
            ->assertJsonPath('stats.confirmed_amount', 5000)
            ->assertJsonPath('stats.confirmed_currency_count', 1);

        $references = collect($response->json('data'))->pluck('reference_number')->all();
        $this->assertContains('LIVE-PAYMENT-001', $references);
        $this->assertContains('LIVE-MANUAL-REVIEW-001', $references);
        $this->assertNotContains('SANDBOX-PAYMENT-001', $references);
        $this->assertNotContains('EXPLICIT-TEST-001', $references);
    }

    public function test_admin_can_include_tests_in_table_while_summary_cards_remain_business_only(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createUser($platform, 'admin');

        $this->createPayment($platform, [
            'transaction_reference' => 'LIVE-PAYMENT-002',
            'amount' => 4200,
            'status' => 'completed',
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'LIVE-MANUAL-REVIEW-002',
            'amount' => 1100,
            'status' => 'completed',
            'reconciliation_state' => 'manual_review',
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'SANDBOX-PAYMENT-002',
            'amount' => 6100,
            'status' => 'completed',
            'provider_environment' => 'sandbox',
            'payment_data' => [
                'test_mode' => true,
                'test_result' => 'completed',
            ],
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'EXPLICIT-TEST-002',
            'amount' => 2300,
            'status' => 'completed',
            'record_classification' => Payment::RECORD_CLASSIFICATION_TEST,
            'test_reason' => 'Training import',
            'test_marked_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&test_visibility=include');

        $response->assertOk()
            ->assertJsonPath('total', 4)
            ->assertJsonPath('can_view_tests', true)
            ->assertJsonPath('test_visibility', 'include')
            ->assertJsonPath('stats_scope', 'business')
            ->assertJsonPath('stats.confirmed', 1)
            ->assertJsonPath('stats.confirmed_amount', 4200);

        $references = collect($response->json('data'))->pluck('reference_number')->all();
        $this->assertContains('LIVE-PAYMENT-002', $references);
        $this->assertContains('LIVE-MANUAL-REVIEW-002', $references);
        $this->assertContains('SANDBOX-PAYMENT-002', $references);
        $this->assertContains('EXPLICIT-TEST-002', $references);
    }

    public function test_admin_can_switch_to_tests_only_mode(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createUser($platform, 'admin');

        $this->createPayment($platform, [
            'transaction_reference' => 'LIVE-PAYMENT-003',
            'amount' => 4200,
            'status' => 'completed',
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'SANDBOX-PAYMENT-003',
            'amount' => 6100,
            'status' => 'completed',
            'provider_environment' => 'sandbox',
            'payment_data' => [
                'test_mode' => true,
                'test_result' => 'completed',
            ],
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'EXPLICIT-TEST-003',
            'amount' => 2300,
            'status' => 'completed',
            'record_classification' => Payment::RECORD_CLASSIFICATION_TEST,
            'test_reason' => 'QA training',
            'test_marked_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&test_visibility=only');

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('test_visibility', 'only')
            ->assertJsonPath('stats_scope', 'test')
            ->assertJsonPath('stats.confirmed', 2)
            ->assertJsonPath('stats.confirmed_amount', 8400);

        $references = collect($response->json('data'))->pluck('reference_number')->all();
        $this->assertNotContains('LIVE-PAYMENT-003', $references);
        $this->assertContains('SANDBOX-PAYMENT-003', $references);
        $this->assertContains('EXPLICIT-TEST-003', $references);
    }

    public function test_sales_user_cannot_request_test_visibility_controls(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform, 'sales');

        Sanctum::actingAs($salesUser);

        $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&test_visibility=include')
            ->assertForbidden();

        $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&environment=sandbox')
            ->assertForbidden();
    }

    public function test_admin_can_mark_payment_as_test_and_delete_unlinked_test_payment(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createUser($platform, 'admin');
        $payment = $this->createPayment($platform, [
            'transaction_reference' => 'LIVE-PAYMENT-004',
            'amount' => 2500,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($admin);

        $markResponse = $this->postJson("/api/crm/payments/{$payment->id}/mark-test", [
            'reason' => 'Mark QA-created payment as non-business.',
        ]);

        $markResponse->assertOk()
            ->assertJsonPath('payment.record_classification', Payment::RECORD_CLASSIFICATION_TEST)
            ->assertJsonPath('payment.test_reason', 'Mark QA-created payment as non-business.');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'record_classification' => Payment::RECORD_CLASSIFICATION_TEST,
        ]);

        $businessView = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);
        $businessView->assertOk()->assertJsonPath('total', 0);

        $deleteResponse = $this->deleteJson("/api/crm/payments/{$payment->id}/delete-test", [
            'reason' => 'Remove isolated QA fixture.',
        ]);

        $deleteResponse->assertOk()
            ->assertJsonPath('deleted_payment_id', $payment->id);

        $this->assertDatabaseMissing('payments', [
            'id' => $payment->id,
        ]);
    }

    public function test_non_admin_cannot_mark_or_delete_test_payments(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform, 'sales');
        $payment = $this->createPayment($platform, [
            'transaction_reference' => 'LIVE-PAYMENT-005',
            'status' => 'completed',
        ]);

        Sanctum::actingAs($salesUser);

        $this->postJson("/api/crm/payments/{$payment->id}/mark-test", [
            'reason' => 'Not allowed.',
        ])->assertForbidden();

        $payment->forceFill([
            'record_classification' => Payment::RECORD_CLASSIFICATION_TEST,
            'test_reason' => 'Already classified',
            'test_marked_at' => now(),
            'test_marked_by' => $salesUser->id,
        ])->save();

        $this->deleteJson("/api/crm/payments/{$payment->id}/delete-test", [
            'reason' => 'Not allowed.',
        ])->assertForbidden();
    }

    public function test_admin_cannot_delete_test_payment_when_live_records_still_reference_it(): void
    {
        $platform = $this->createPlatform();
        $admin = $this->createUser($platform, 'admin');
        $payment = $this->createPayment($platform, [
            'transaction_reference' => 'EXPLICIT-TEST-DELETE-BLOCKED',
            'status' => 'completed',
            'record_classification' => Payment::RECORD_CLASSIFICATION_TEST,
            'test_reason' => 'Linked QA record',
            'test_marked_at' => now()->subMinute(),
            'wallet_transaction_id' => 99,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/crm/payments/{$payment->id}/delete-test", [
            'reason' => 'Attempt delete despite live linkage.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('blockers.0', 'payment_has_wallet_transaction');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
        ]);
    }

    public function test_explicit_test_payment_cannot_create_subscription_from_queue(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform, 'sales');
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'phone_normalized' => '254700000777',
        ]);

        $payment = $this->createPayment($platform, [
            'transaction_reference' => 'EXPLICIT-TEST-NO-SUB',
            'status' => 'completed',
            'client_id' => $client->id,
            'reconciliation_confidence' => 'high',
            'record_classification' => Payment::RECORD_CLASSIFICATION_TEST,
            'test_reason' => 'QA activation trial',
            'test_marked_at' => now()->subMinute(),
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/create-subscription", [
            'reason' => 'Try to activate from a test payment.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Test or sandbox payments cannot create live subscriptions.');
    }

    public function test_confirmed_stats_and_completed_filter_include_expired_successful_payments(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform, 'sales');

        $this->createPayment($platform, [
            'transaction_reference' => 'SUCCESS-COMPLETED-001',
            'amount' => 1500,
            'status' => 'completed',
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'SUCCESS-EXPIRED-001',
            'amount' => 59.2,
            'status' => 'expired',
        ]);

        Sanctum::actingAs($salesUser);

        $summaryResponse = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);
        $summaryResponse->assertOk()
            ->assertJsonPath('stats.confirmed', 2)
            ->assertJsonPath('stats.confirmed_currency_count', 1);
        $this->assertSame(1559.2, (float) $summaryResponse->json('stats.confirmed_amount'));

        $completedFilterResponse = $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&status=completed');
        $completedFilterResponse->assertOk()
            ->assertJsonPath('total', 2);
        $completedReferences = collect($completedFilterResponse->json('data'))->pluck('reference_number')->all();
        $this->assertContains('SUCCESS-COMPLETED-001', $completedReferences);
        $this->assertContains('SUCCESS-EXPIRED-001', $completedReferences);

        $expiredFilterResponse = $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&status=expired');
        $expiredFilterResponse->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.reference_number', 'SUCCESS-EXPIRED-001');
    }

    public function test_mpesa_review_uses_authenticated_user_market_access(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform, 'sales');

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/payments/mpesa-review');

        $response->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.total_review', 0);
    }

    public function test_payment_workspace_can_filter_by_resolution_code(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform, 'sales');

        $this->createPayment($platform, [
            'transaction_reference' => 'RESOLUTION-REV-001',
            'reference_number' => 'RESOLUTION-REV-001',
            'status' => 'completed',
            'resolution_code' => 'reversed',
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'RESOLUTION-INV-001',
            'reference_number' => 'RESOLUTION-INV-001',
            'status' => 'failed',
            'resolution_code' => 'invalid_reference',
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'RESOLUTION-LIVE-001',
            'reference_number' => 'RESOLUTION-LIVE-001',
            'status' => 'completed',
            'resolution_code' => null,
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id . '&resolution_code=reversed');

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.reference_number', 'RESOLUTION-REV-001')
            ->assertJsonPath('data.0.resolution_code', 'reversed');
    }

    public function test_business_visible_scope_hides_committing_bundle_rows_from_workspace_and_stats(): void
    {
        $platform = $this->createPlatform();
        $salesUser = $this->createUser($platform, 'sales');
        $bundle = ManualPaymentBundle::factory()->create([
            'platform_id' => $platform->id,
            'status' => ManualPaymentBundle::STATUS_COMMITTING,
            'audit_state' => ManualPaymentBundle::AUDIT_PENDING_FINANCE_REVIEW,
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'LIVE-VISIBLE-001',
            'reference_number' => 'LIVE-VISIBLE-001',
            'status' => 'completed',
        ]);

        $this->createPayment($platform, [
            'transaction_reference' => 'BUNDLE-HIDDEN-001',
            'reference_number' => 'BUNDLE-HIDDEN-001',
            'reference_root' => $bundle->reference_root,
            'reference_sequence' => 1,
            'manual_payment_bundle_id' => $bundle->id,
            'status' => 'completed',
        ]);

        Sanctum::actingAs($salesUser);

        $response = $this->getJson('/api/crm/payments?platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('stats.confirmed', 1);

        $references = collect($response->json('data'))->pluck('reference_number')->all();
        $this->assertContains('LIVE-VISIBLE-001', $references);
        $this->assertNotContains('BUNDLE-HIDDEN-001', $references);
    }

    private function createPlatform(string $country = 'Kenya', string $currencyCode = 'KES'): Platform
    {
        return Platform::query()->create([
            'name' => $country . ' Sandbox Visibility Market',
            'domain' => 'sandbox-visibility-' . Str::random(6) . '.example.test',
            'country' => $country,
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => $currencyCode,
            'is_active' => true,
            'wp_api_url' => 'https://sandbox-visibility.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createUser(Platform $platform, string $role): User
    {
        return User::query()->create([
            'name' => ucfirst(str_replace('_', ' ', $role)) . ' User',
            'email' => strtolower($role) . '-' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
    }

    private function createPayment(Platform $platform, array $attributes = []): Payment
    {
        $reference = $attributes['transaction_reference'] ?? ('PAY-' . Str::upper(Str::random(8)));
        $createdAt = $attributes['created_at'] ?? now()->subMinutes(5);

        return Payment::query()->create(array_merge([
            'platform_id' => $platform->id,
            'phone' => '254700' . random_int(100000, 999999),
            'amount' => 1000,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => $reference,
            'reference_number' => $reference,
            'status' => 'initiated',
            'purpose' => 'subscription',
            'provider_environment' => 'production',
            'payment_data' => [],
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $attributes));
    }
}
