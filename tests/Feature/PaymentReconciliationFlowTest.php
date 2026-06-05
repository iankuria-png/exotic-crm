<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Payment;
use App\Models\PaymentReconciliationBatch;
use App\Models\PaymentReconciliationRow;
use App\Models\Platform;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentReconciliationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_classifies_external_rows_without_creating_payments(): void
    {
        $platform = $this->createPlatform('Kenya');
        $admin = $this->createUser('admin');
        $client = $this->createClient($platform, 'Sexy Gold');
        $otherClient = $this->createClient($platform, 'Different Client');
        $recorder = $this->createUser('sales', [$platform->id]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'confirmed_by' => $recorder->id,
            'amount' => 10000,
            'currency' => 'KES',
            'transaction_reference' => 'T_OLIAPWIBZTZ5NA4K',
            'status' => 'completed',
        ]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'client_id' => $otherClient->id,
            'confirmed_by' => $recorder->id,
            'amount' => 5000,
            'currency' => 'KES',
            'transaction_reference' => 'T_MISMATCH',
            'status' => 'completed',
        ]);

        Payment::query()->create([
            'platform_id' => $platform->id,
            'amount' => 1000,
            'currency' => 'KES',
            'transaction_reference' => 'DUP-CRM',
            'status' => 'completed',
        ]);
        Payment::query()->create([
            'platform_id' => $platform->id,
            'amount' => 1000,
            'currency' => 'KES',
            'transaction_reference' => 'DUPCRM',
            'status' => 'completed',
        ]);

        Sanctum::actingAs($admin);

        $csv = implode("\n", [
            'Client Name,Amount Paid,Date Paid,Transaction ID,Activated,Who Activated,CRM Transaction ID',
            'Sexy Gold,"10,000",29th April,T_OLIAPWIBZTZ5NA4K,,Rosemary,',
            'Sexy Gold,"6,000",30th April,T_MISMATCH,,,',
            'Missing Client,"10,000",1st May,T_MISSING,,,',
            'No Ref,"10,000",2nd May,Direct Transfer,,,',
            'Short Ref,"10,000",3rd May,T-,,,',
            'Dup File,"10,000",4th May,T_DUP_FILE,,,',
            'Dup File,"10,000",5th May,T_DUP_FILE,,,',
            'Dup CRM,"1,000",6th May,DUP-CRM,,,',
        ]);

        $response = $this->postJson('/api/crm/payments/reconcile/preview', [
            'platform_id' => $platform->id,
            'file' => UploadedFile::fake()->createWithContent('rosemary.csv', $csv),
            'has_header' => true,
            'reason' => 'Audit Rosemary payments',
        ]);

        $response->assertOk()
            ->assertJsonPath('summary.total_rows', 8)
            ->assertJsonPath('summary.matched_rows', 1)
            ->assertJsonPath('summary.mismatch_rows', 1)
            ->assertJsonPath('summary.missing_rows', 2)
            ->assertJsonPath('summary.unverifiable_rows', 2)
            ->assertJsonPath('summary.duplicate_rows', 2);

        $rows = collect($response->json('rows'));
        $this->assertSame('matched', $rows->firstWhere('external_reference_raw', 'T_OLIAPWIBZTZ5NA4K')['classification']);
        $this->assertSame('amount_mismatch', $rows->firstWhere('external_reference_raw', 'T_MISMATCH')['classification']);
        $this->assertSame('missing', $rows->firstWhere('external_reference_raw', 'T_MISSING')['classification']);
        $this->assertSame('unverifiable', $rows->firstWhere('external_reference_raw', 'Direct Transfer')['classification']);
        $this->assertSame('unverifiable', $rows->firstWhere('external_reference_raw', 'T-')['classification']);
        $this->assertSame('duplicate_in_file', $rows->where('external_reference_raw', 'T_DUP_FILE')->last()['classification']);
        $this->assertSame('duplicate_in_crm', $rows->firstWhere('external_reference_raw', 'DUP-CRM')['classification']);

        $this->assertSame(4, Payment::query()->where('platform_id', $platform->id)->count());
        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'action' => CrmAuditAction::PAYMENT_RECON_PREVIEW,
            'entity_type' => 'payment_reconciliation_batch',
            'entity_id' => (int) $response->json('batch_id'),
        ]);
    }

    public function test_single_code_paste_creates_one_classified_row(): void
    {
        $platform = $this->createPlatform('Tanzania');
        $admin = $this->createUser('admin');

        Payment::query()->create([
            'platform_id' => $platform->id,
            'amount' => 10000,
            'currency' => 'TZS',
            'transaction_reference' => 'T_3XPI5TR2K4Y3KUA6',
            'status' => 'completed',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/crm/payments/reconcile/preview', [
            'platform_id' => $platform->id,
            'pasted_text' => 'T_3XPI5TR2K4Y3KUA6',
            'reason' => 'Single code check',
        ]);

        $response->assertOk()
            ->assertJsonPath('summary.total_rows', 1)
            ->assertJsonPath('rows.0.classification', 'matched');
    }

    public function test_review_link_close_and_scope_guards(): void
    {
        $platform = $this->createPlatform('Uganda');
        $otherPlatform = $this->createPlatform('Rwanda');
        $admin = $this->createUser('admin');
        $sales = $this->createUser('sales', [$platform->id]);
        $payment = Payment::query()->create([
            'platform_id' => $platform->id,
            'amount' => 9000,
            'currency' => 'UGX',
            'transaction_reference' => 'CRM-LINK-1',
            'status' => 'completed',
            'reconciliation_state' => 'open',
        ]);
        $otherPayment = Payment::query()->create([
            'platform_id' => $otherPlatform->id,
            'amount' => 9000,
            'currency' => 'RWF',
            'transaction_reference' => 'OTHER-LINK-1',
            'status' => 'completed',
        ]);

        Sanctum::actingAs($sales);
        $this->getJson('/api/crm/payments/reconcile/batches')->assertForbidden();

        Sanctum::actingAs($admin);
        $preview = $this->postJson('/api/crm/payments/reconcile/preview', [
            'platform_id' => $platform->id,
            'pasted_text' => "Missing Client\t9000\t1st May\tMISSING-LINK-1",
            'reason' => 'Create link row',
        ]);
        $preview->assertOk()->assertJsonPath('rows.0.classification', 'missing');

        $batchId = (int) $preview->json('batch_id');
        $rowId = (int) $preview->json('rows.0.id');

        $crossPlatform = $this->postJson("/api/crm/payments/reconcile/rows/{$rowId}/link", [
            'payment_id' => $otherPayment->id,
            'reason' => 'Should fail cross platform',
        ]);
        $crossPlatform->assertStatus(422);

        $review = $this->postJson("/api/crm/payments/reconcile/rows/{$rowId}/review", [
            'status' => 'confirmed_fraud',
            'reason' => 'Confirmed siphoned payment',
            'note' => 'No CRM payment found.',
        ]);
        $review->assertOk()->assertJsonPath('row.review_status', 'confirmed_fraud');

        $link = $this->postJson("/api/crm/payments/reconcile/rows/{$rowId}/link", [
            'payment_id' => $payment->id,
            'reason' => 'Link to payment for operational review',
        ]);
        $link->assertOk()->assertJsonPath('row.review_status', 'linked');
        $this->assertSame('manual_review', $payment->fresh()->reconciliation_state);

        $this->getJson("/api/crm/payments/reconcile/batches/{$batchId}?review_status=resolved")
            ->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.id', $rowId);

        $close = $this->postJson("/api/crm/payments/reconcile/batches/{$batchId}/close", [
            'reason' => 'Review complete',
        ]);
        $close->assertOk()->assertJsonPath('batch.status', 'closed');

        $blocked = $this->postJson("/api/crm/payments/reconcile/rows/{$rowId}/review", [
            'status' => 'cleared',
            'reason' => 'Try after close',
        ]);
        $blocked->assertStatus(422);

        $reopen = $this->postJson("/api/crm/payments/reconcile/batches/{$batchId}/reopen", [
            'reason' => 'Need another look',
        ]);
        $reopen->assertOk()->assertJsonPath('batch.status', 'reviewing');

        $this->assertDatabaseHas('payment_reconciliation_batches', [
            'id' => $batchId,
            'resolved_rows' => 1,
        ]);

        foreach ([
            CrmAuditAction::PAYMENT_RECON_ROW_REVIEW,
            CrmAuditAction::PAYMENT_RECON_ROW_LINK,
            CrmAuditAction::PAYMENT_RECON_BATCH_CLOSE,
            CrmAuditAction::PAYMENT_REVIEW_STATE_UPDATE,
        ] as $action) {
            $this->assertTrue(
                AuditLog::query()->where('action', $action)->exists(),
                "Missing audit action {$action}"
            );
        }
    }

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' ' . Str::random(6),
            'email' => Str::random(10) . '@example.test',
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

    private function createClient(Platform $platform, string $name): Client
    {
        return Client::query()->create([
            'platform_id' => $platform->id,
            'name' => $name,
            'phone_normalized' => '2547' . random_int(10000000, 99999999),
            'profile_status' => 'publish',
            'wp_post_id' => random_int(10000, 99999),
        ]);
    }
}
