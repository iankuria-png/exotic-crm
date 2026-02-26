<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentImportBatch;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentReconciliationConfidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_subscription_requires_high_reconciliation_confidence(): void
    {
        $platform = $this->createPlatform('Kenya');
        $user = $this->createUser('sales', [$platform->id]);
        $client = $this->createClient($platform);
        $product = $this->createProduct();

        $lowConfidencePayment = Payment::query()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'phone' => '0711000001',
            'amount' => 2500,
            'currency' => 'KES',
            'status' => 'completed',
            'reconciliation_confidence' => 'low',
            'reconciliation_state' => 'manual_review',
        ]);

        Sanctum::actingAs($user);

        $blocked = $this->postJson("/api/crm/payments/{$lowConfidencePayment->id}/create-subscription", [
            'reason' => 'Attempt on low-confidence payment',
        ]);

        $blocked->assertStatus(422)
            ->assertJsonPath('message', 'Subscription creation requires high-confidence reconciliation. Confirm the payment match first.');

        $highConfidencePayment = Payment::query()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'phone' => '0711000002',
            'amount' => 2500,
            'currency' => 'KES',
            'status' => 'completed',
            'reconciliation_confidence' => 'high',
            'reconciliation_state' => 'resolved',
        ]);

        $allowed = $this->postJson("/api/crm/payments/{$highConfidencePayment->id}/create-subscription", [
            'reason' => 'Create subscription for high-confidence payment',
        ]);

        $allowed->assertStatus(201)
            ->assertJsonPath('payment.id', $highConfidencePayment->id);

        $this->assertNotNull($highConfidencePayment->fresh()->deal_id);
    }

    public function test_review_state_endpoint_marks_manual_review_and_resolved(): void
    {
        $platform = $this->createPlatform('Tanzania');
        $user = $this->createUser('sales', [$platform->id]);

        $payment = Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '0711000003',
            'amount' => 1800,
            'currency' => 'KES',
            'status' => 'completed',
            'reconciliation_confidence' => 'low',
            'reconciliation_state' => 'open',
        ]);

        Sanctum::actingAs($user);

        $markReview = $this->postJson("/api/crm/payments/{$payment->id}/review-state", [
            'state' => 'manual_review',
            'reason' => 'Identifiers are weak',
        ]);

        $markReview->assertOk()
            ->assertJsonPath('payment.reconciliation_state', 'manual_review');

        $resolve = $this->postJson("/api/crm/payments/{$payment->id}/review-state", [
            'state' => 'resolved',
            'reason' => 'Manual review completed',
        ]);

        $resolve->assertOk()
            ->assertJsonPath('payment.reconciliation_state', 'resolved');

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => CrmAuditAction::PAYMENT_REVIEW_STATE_UPDATE,
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
        ]);
    }

    public function test_payment_index_filters_by_import_source_and_confidence_band(): void
    {
        $platform = $this->createPlatform('Uganda');
        $user = $this->createUser('sales', [$platform->id]);

        $targetPayment = Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '0711000004',
            'amount' => 2000,
            'currency' => 'KES',
            'status' => 'completed',
            'source' => 'excel_import',
            'reconciliation_confidence' => 'low',
            'reconciliation_state' => 'manual_review',
        ]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '0711000005',
            'amount' => 2000,
            'currency' => 'KES',
            'status' => 'completed',
            'source' => 'gateway',
            'reconciliation_confidence' => 'high',
            'reconciliation_state' => 'resolved',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/payments?source=excel_import&match_confidence=low&review_state=manual_review');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $targetPayment->id);
    }

    public function test_import_template_download_returns_csv_headers(): void
    {
        $platform = $this->createPlatform('Rwanda');
        $user = $this->createUser('sales', [$platform->id]);
        Sanctum::actingAs($user);

        $response = $this->get('/api/crm/payments/import/template');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $response->assertSee('payment_date,amount,currency,phone,transaction_reference,status,profile_url,subscription_type,notes');
    }

    public function test_import_kpis_returns_metrics_for_scoped_market(): void
    {
        $platform = $this->createPlatform('Botswana');
        $otherPlatform = $this->createPlatform('Namibia');
        $user = $this->createUser('sales', [$platform->id]);

        PaymentImportBatch::query()->create([
            'platform_id' => $platform->id,
            'uploaded_by' => $user->id,
            'file_name' => 'batch-a.csv',
            'status' => 'committed',
            'total_rows' => 10,
            'valid_rows' => 8,
            'invalid_rows' => 1,
            'duplicate_rows' => 1,
            'committed_rows' => 8,
        ]);

        PaymentImportBatch::query()->create([
            'platform_id' => $otherPlatform->id,
            'uploaded_by' => $user->id,
            'file_name' => 'batch-b.csv',
            'status' => 'committed',
            'total_rows' => 20,
            'valid_rows' => 18,
            'invalid_rows' => 1,
            'duplicate_rows' => 1,
            'committed_rows' => 18,
        ]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '0711000010',
            'amount' => 2100,
            'currency' => 'KES',
            'status' => 'completed',
            'source' => 'excel_import',
            'reconciliation_confidence' => 'high',
            'reconciliation_state' => 'resolved',
        ]);

        $manualReviewPayment = Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '0711000011',
            'amount' => 2200,
            'currency' => 'KES',
            'status' => 'completed',
            'source' => 'excel_import',
            'reconciliation_confidence' => 'low',
            'reconciliation_state' => 'manual_review',
        ]);
        $manualReviewPayment->forceFill(['created_at' => now()->subDays(20)])->saveQuietly();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/payments/import/kpis?platform_id=' . $platform->id);

        $response->assertOk()
            ->assertJsonPath('kpis.batches', 1)
            ->assertJsonPath('kpis.rows_total', 10)
            ->assertJsonPath('kpis.rows_committed', 8)
            ->assertJsonPath('kpis.rows_duplicate', 1)
            ->assertJsonPath('kpis.rows_invalid', 1)
            ->assertJsonPath('kpis.payments_imported', 2)
            ->assertJsonPath('kpis.manual_review_open', 1)
            ->assertJsonPath('aging.gt_14d', 1);
    }

    private function createUser(string $role = 'sales', array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' ' . Str::random(6),
            'email' => Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
        ]);
    }

    private function createPlatform(string $name): Platform
    {
        return Platform::query()->create([
            'name' => $name,
            'domain' => Str::slug($name) . '-' . Str::random(6) . '.test',
            'country' => $name,
            'is_active' => true,
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'wp_api_url' => 'https://example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createClient(Platform $platform): Client
    {
        return Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => random_int(1000, 999999),
            'name' => 'Client ' . Str::random(5),
            'phone_normalized' => '2547' . random_int(10000000, 99999999),
            'profile_status' => 'publish',
        ]);
    }

    private function createProduct(): Product
    {
        return Product::query()->create([
            'name' => 'Premium Plan',
            'monthly_price' => 2500,
            'biweekly_price' => 1500,
            'weekly_price' => 900,
            'currency' => 'KES',
            'is_active' => true,
        ]);
    }
}
