<?php

namespace Tests\Feature;

use App\Models\BillingProviderTransaction;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Platform;
use App\Models\User;
use App\Services\PaymentRecoveryMetricService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentRecoveryMetricTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_counts_recovered_failures_and_distinct_customer_identities(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'KES']);
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        $this->payment($platform, [
            'phone' => '+254 700 000 001',
            'status' => 'failed',
            'created_at' => '2026-06-01 09:00:00',
        ]);
        $this->payment($platform, [
            'phone' => '254700000001',
            'status' => 'completed',
            'created_at' => '2026-06-01 09:05:00',
            'completed_at' => '2026-06-01 09:10:00',
        ]);

        $this->payment($platform, [
            'client_id' => $client->id,
            'phone' => '254700000002',
            'status' => 'failed',
            'created_at' => '2026-06-02 09:00:00',
        ]);
        $this->payment($platform, [
            'client_id' => $client->id,
            'phone' => '254711111111',
            'status' => 'completed',
            'created_at' => '2026-06-02 09:05:00',
            'completed_at' => '2026-06-02 09:10:00',
        ]);
        $this->payment($platform, [
            'client_id' => $client->id,
            'phone' => '254722222222',
            'status' => 'failed',
            'created_at' => '2026-06-03 09:00:00',
        ]);

        $this->payment($platform, [
            'phone' => '254700000003',
            'status' => 'failed',
            'created_at' => '2026-06-04 09:00:00',
        ]);
        $this->payment($platform, [
            'phone' => '254700000003',
            'status' => 'completed',
            'created_at' => '2026-06-11 09:05:00',
            'completed_at' => '2026-06-11 09:10:00',
        ]);

        $this->payment($platform, [
            'phone' => '254700000004',
            'status' => 'failed',
            'purpose' => 'wallet_topup',
            'created_at' => '2026-06-05 09:00:00',
        ]);
        $this->payment($platform, [
            'phone' => '254700000004',
            'status' => 'completed',
            'purpose' => 'wallet_topup',
            'created_at' => '2026-06-05 09:05:00',
            'completed_at' => '2026-06-05 09:10:00',
        ]);

        $metrics = app(PaymentRecoveryMetricService::class)->compute(
            [$platform->id],
            Carbon::parse('2026-06-01')->startOfDay(),
            Carbon::parse('2026-06-10')->endOfDay()
        );

        $this->assertSame(4, $metrics['failed_payments']);
        $this->assertSame(2, $metrics['recovered_payments']);
        $this->assertSame(2, $metrics['lost_payments']);
        $this->assertSame(50.0, $metrics['payment_recovery_rate']);
        $this->assertSame(3, $metrics['failed_customers']);
        $this->assertSame(2, $metrics['recovered_customers']);
        $this->assertSame(66.7, $metrics['customer_recovery_rate']);
    }

    public function test_dashboard_and_ceo_summary_expose_failed_payment_recovery_metric(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'KES']);
        $this->payment($platform, [
            'phone' => '254700000010',
            'status' => 'failed',
            'created_at' => '2026-06-01 10:00:00',
        ]);
        $this->payment($platform, [
            'phone' => '254700000010',
            'status' => 'completed',
            'created_at' => '2026-06-01 10:05:00',
            'completed_at' => '2026-06-01 10:10:00',
        ]);
        $this->payment($platform, [
            'phone' => '254700000011',
            'status' => 'failed',
            'purpose' => 'wallet_topup',
            'created_at' => '2026-06-02 10:00:00',
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'is_ceo' => true]);
        Sanctum::actingAs($admin);

        $this->getJson("/api/crm/payments?platform_id={$platform->id}&from=2026-06-01&to=2026-06-10")
            ->assertOk()
            ->assertJsonPath('stats.failed', 1);

        $this->getJson("/api/crm/dashboard?platform_id={$platform->id}&from=2026-06-01&to=2026-06-10")
            ->assertOk()
            ->assertJsonPath('kpis.failed_payment_recovery.failed_payments', 1)
            ->assertJsonPath('kpis.failed_payment_recovery.recovered_payments', 1)
            ->assertJsonPath('kpis.failed_payment_recovery.lost_payments', 0)
            ->assertJsonPath('kpis.failed_payment_recovery.payment_recovery_rate', 100);

        $this->getJson("/api/crm/dashboard/ceo/summary?platform_id={$platform->id}&horizon=custom&from=2026-06-01&to=2026-06-10&reporting_currency=USD")
            ->assertOk()
            ->assertJsonPath('metrics.failed_payment_recovery.label', 'Failed Payment Recovery')
            ->assertJsonPath('metrics.failed_payment_recovery.value.failed_payments', 1)
            ->assertJsonPath('metrics.failed_payment_recovery.value.recovered_payments', 1)
            ->assertJsonPath('metrics.failed_payment_recovery.value.payment_recovery_rate', 100);

        $this->getJson("/api/crm/payments/recovery-report?platform_id={$platform->id}&from=2026-06-01&to=2026-06-10")
            ->assertOk()
            ->assertJsonPath('filters.currency_mode', 'native')
            ->assertJsonPath('metrics.failed_payments', 1)
            ->assertJsonPath('metrics.recovered_payments', 1)
            ->assertJsonPath('metrics.lost_payments', 0)
            ->assertJsonPath('recovered_pairs.0.failed_payment.status', 'failed')
            ->assertJsonPath('recovered_pairs.0.recovered_payment.status', 'completed');

        $this->getJson("/api/crm/payments/recovery-report?platform_id={$platform->id}&from=2026-06-01&to=2026-06-10&currency_mode=flat&reporting_currency=KES")
            ->assertOk()
            ->assertJsonPath('filters.currency_mode', 'flat')
            ->assertJsonPath('filters.reporting_currency', 'KES')
            ->assertJsonPath('metrics.normalized_currency', 'KES')
            ->assertJsonPath('metrics.recovered_normalization_meta.target_currency', 'KES');
    }

    public function test_recovery_report_normalizes_cfa_with_market_context(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Senegal',
            'country' => 'Senegal',
            'currency_code' => 'CFA',
        ]);

        $this->payment($platform, [
            'phone' => '221700000001',
            'status' => 'failed',
            'amount' => 100,
            'currency' => 'CFA',
            'created_at' => '2026-06-01 10:00:00',
        ]);
        $this->payment($platform, [
            'phone' => '221700000001',
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'CFA',
            'created_at' => '2026-06-01 10:05:00',
            'completed_at' => '2026-06-01 10:10:00',
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->getJson("/api/crm/payments/recovery-report?platform_id={$platform->id}&from=2026-06-01&to=2026-06-10&currency_mode=flat&reporting_currency=XOF")
            ->assertOk()
            ->assertJsonPath('metrics.normalized_currency', 'XOF')
            ->assertJsonPath('metrics.recovered_normalized_amount', 100)
            ->assertJsonPath('metrics.recovered_normalization_meta.partial', false)
            ->assertJsonPath('metrics.recovered_normalization_meta.currency_aliases.0.canonical_currency', 'XOF');
    }

    public function test_recovery_report_ranks_failure_reasons_and_splits_recovery_outcomes(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'KES']);

        $recoveredTimeout = $this->payment($platform, [
            'phone' => '254700000101',
            'status' => 'failed',
            'amount' => 1000,
            'failure_reason' => 'Fallback wording should not override structured data.',
            'created_at' => '2026-06-01 09:00:00',
        ]);
        PaymentAttempt::query()->create([
            'payment_id' => $recoveredTimeout->id,
            'attempt_type' => 'stk_initiate',
            'provider' => 'pawapay',
            'status' => 'failed',
            'error_code' => 'callback_processing',
            'error_message' => 'Customer declined the request.',
            'created_at' => '2026-06-01 09:01:00',
            'updated_at' => '2026-06-01 09:01:00',
        ]);
        $this->payment($platform, [
            'phone' => '254700000101',
            'status' => 'completed',
            'amount' => 1000,
            'created_at' => '2026-06-01 09:05:00',
            'completed_at' => '2026-06-01 09:10:00',
        ]);

        $unresolvedTimeout = $this->payment($platform, [
            'phone' => '254700000102',
            'status' => 'failed',
            'amount' => 500,
            'failure_reason' => 'The customer did not authorize the payment in time.',
            'created_at' => '2026-06-02 09:00:00',
        ]);
        BillingProviderTransaction::query()->create([
            'payment_id' => $unresolvedTimeout->id,
            'provider_type_key' => 'pawapay',
            'normalized_status' => 'failed',
            'provider_transaction_id' => (string) Str::uuid(),
            'provider_failure_code' => 'user_timeout',
            'provider_failure_message' => 'Provider-specific timeout.',
            'last_status_at' => '2026-06-02 09:01:00',
        ]);

        $this->payment($platform, [
            'phone' => '254700000103',
            'status' => 'failed',
            'amount' => 250,
            'failure_reason' => 'Insufficient funds in customer account.',
            'created_at' => '2026-06-03 09:00:00',
        ]);
        $this->payment($platform, [
            'phone' => '254700000104',
            'status' => 'failed',
            'amount' => 125,
            'failure_reason' => 'Unexpected terminal state alpha.',
            'created_at' => '2026-06-04 09:00:00',
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $response = $this->getJson(
            "/api/crm/payments/recovery-report?platform_id={$platform->id}&from=2026-06-01&to=2026-06-10&currency_mode=flat&reporting_currency=KES"
        );

        $response
            ->assertOk()
            ->assertJsonPath('failure_reasons.total', 4)
            ->assertJsonPath('failure_reasons.classified', 3)
            ->assertJsonPath('failure_reasons.unclassified', 1)
            ->assertJsonPath('failure_reasons.coverage_pct', 75)
            ->assertJsonPath('failure_reasons.items.0.code', 'authorization_timeout')
            ->assertJsonPath('failure_reasons.items.0.failed_count', 2)
            ->assertJsonPath('failure_reasons.items.0.recovered_count', 1)
            ->assertJsonPath('failure_reasons.items.0.unresolved_count', 1)
            ->assertJsonPath('failure_reasons.items.0.recovery_rate', 50)
            ->assertJsonPath('failure_reasons.items.0.failed_normalized_amount', 1500)
            ->assertJsonPath('failure_reasons.items.0.normalized_currency', 'KES')
            ->assertJsonMissingPath('failure_reasons.items.0.failed_amount_rows');
    }

    public function test_failure_reason_aggregation_preserves_business_visibility_and_market_scope(): void
    {
        $visiblePlatform = Platform::factory()->create(['currency_code' => 'KES']);
        $otherPlatform = Platform::factory()->create(['currency_code' => 'UGX']);

        $this->payment($visiblePlatform, [
            'status' => 'failed',
            'failure_reason' => 'Customer declined the request.',
            'created_at' => '2026-06-01 09:00:00',
        ]);
        $this->payment($visiblePlatform, [
            'status' => 'failed',
            'failure_reason' => 'Insufficient funds.',
            'purpose' => 'wallet_topup',
            'created_at' => '2026-06-02 09:00:00',
        ]);
        $this->payment($visiblePlatform, [
            'status' => 'failed',
            'failure_reason' => 'Insufficient funds.',
            'record_classification' => Payment::RECORD_CLASSIFICATION_TEST,
            'created_at' => '2026-06-03 09:00:00',
        ]);
        $this->payment($visiblePlatform, [
            'status' => 'failed',
            'failure_reason' => 'Insufficient funds.',
            'provider_environment' => 'sandbox',
            'created_at' => '2026-06-04 09:00:00',
        ]);
        $this->payment($otherPlatform, [
            'status' => 'failed',
            'failure_reason' => 'Insufficient funds.',
            'created_at' => '2026-06-05 09:00:00',
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->getJson(
            "/api/crm/payments/recovery-report?platform_id={$visiblePlatform->id}&from=2026-06-01&to=2026-06-10"
        )
            ->assertOk()
            ->assertJsonPath('failure_reasons.total', 1)
            ->assertJsonCount(1, 'failure_reasons.items')
            ->assertJsonPath('failure_reasons.items.0.code', 'customer_declined');
    }

    public function test_empty_recovery_report_has_empty_failure_reason_contract(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'KES']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Sanctum::actingAs($admin);

        $this->getJson(
            "/api/crm/payments/recovery-report?platform_id={$platform->id}&from=2026-06-01&to=2026-06-10"
        )
            ->assertOk()
            ->assertJsonPath('failure_reasons.total', 0)
            ->assertJsonPath('failure_reasons.classified', 0)
            ->assertJsonPath('failure_reasons.unclassified', 0)
            ->assertJsonPath('failure_reasons.coverage_pct', 0)
            ->assertJsonCount(0, 'failure_reasons.items');
    }

    public function test_recovery_report_rejects_a_market_outside_the_users_scope(): void
    {
        $allowedPlatform = Platform::factory()->create(['currency_code' => 'KES']);
        $blockedPlatform = Platform::factory()->create(['currency_code' => 'UGX']);
        $salesUser = User::factory()->create([
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [$allowedPlatform->id],
        ]);
        Sanctum::actingAs($salesUser);

        $this->getJson(
            "/api/crm/payments/recovery-report?platform_id={$blockedPlatform->id}&from=2026-06-01&to=2026-06-10"
        )
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this payment market.');
    }

    private function payment(Platform $platform, array $overrides = []): Payment
    {
        $createdAt = $overrides['created_at'] ?? '2026-06-01 00:00:00';
        $status = $overrides['status'] ?? 'completed';

        return Payment::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'product_id' => null,
            'phone' => '2547' . random_int(1000000, 9999999),
            'status' => 'completed',
            'purpose' => 'subscription',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => Str::upper(Str::random(10)),
            'record_classification' => Payment::RECORD_CLASSIFICATION_LIVE,
            'provider_environment' => 'production',
            'payment_data' => null,
            'reconciliation_state' => 'open',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            'completed_at' => in_array($status, Payment::SUCCESSFUL_STATUSES, true)
                ? ($overrides['completed_at'] ?? $createdAt)
                : null,
        ], $overrides));
    }
}
