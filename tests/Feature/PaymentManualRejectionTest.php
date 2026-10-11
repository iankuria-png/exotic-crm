<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\PaymentManualSubmission;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentManualRejectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_rejection_deactivates_active_linked_deal_with_structured_request(): void
    {
        [$platform, $client, $payment, $deal, $submission] = $this->createManualReviewFixture(withActiveDeal: true);
        $user = $this->createUser($platform, 'admin');
        $this->fakeWordPressForManualRejection($platform, $client, 'private');

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/manual-reject", [
            'reason' => 'Receipt not valid',
        ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Manual payment rejected.')
            ->assertJsonPath('payment.id', $payment->id)
            ->assertJsonPath('payment.status', 'failed')
            ->assertJsonPath('payment.reconciliation_state', 'resolved')
            ->assertJsonPath('payment.manual_submission.review_decision', 'rejected');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'failed',
            'reconciliation_state' => 'resolved',
            'resolution_code' => Payment::RESOLUTION_INVALID_REFERENCE,
            'failure_reason' => 'Receipt not valid',
        ]);

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'status' => 'cancelled',
            'cancellation_reason_code' => 'invalid_reference',
            'cancelled_payment_id' => $payment->id,
        ]);

        $this->assertDatabaseHas('payment_manual_submissions', [
            'id' => $submission->id,
            'review_decision' => 'rejected',
            'reviewed_by' => $user->id,
            'rejection_reason' => 'Receipt not valid',
        ]);

        Http::assertSent(function ($request) use ($platform, $client) {
            return $request->url() === rtrim($platform->wp_api_url, '/') . "/clients/{$client->wp_post_id}/deactivate"
                && $request->method() === 'POST';
        });

        Http::assertSent(function ($request) use ($platform, $client) {
            return $request->url() === rtrim($platform->wp_api_url, '/') . "/clients/{$client->wp_post_id}/manual-payment-state"
                && $request->method() === 'POST'
                && data_get($request->data(), 'fields.billing_manual_payment_state') === 'rejected'
                && data_get($request->data(), 'fields.billing_manual_payment_reason') === 'Receipt not valid';
        });
    }

    public function test_manual_rejection_succeeds_without_deactivating_when_there_is_no_active_deal(): void
    {
        [$platform, $client, $payment, $deal, $submission] = $this->createManualReviewFixture(withActiveDeal: false);
        $user = $this->createUser($platform, 'admin');
        $this->fakeWordPressForManualRejection($platform, $client, 'publish');

        Sanctum::actingAs($user);

        $response = $this->postJson("/api/crm/payments/{$payment->id}/manual-reject", [
            'reason' => 'Invalid bank reference',
        ]);

        $response->assertOk()
            ->assertJsonPath('payment.status', 'failed')
            ->assertJsonPath('payment.manual_submission.review_decision', 'rejected');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'status' => 'failed',
            'reconciliation_state' => 'resolved',
        ]);

        $this->assertDatabaseHas('payment_manual_submissions', [
            'id' => $submission->id,
            'review_decision' => 'rejected',
            'rejection_reason' => 'Invalid bank reference',
        ]);

        $this->assertDatabaseHas('deals', [
            'id' => $deal->id,
            'status' => 'pending',
            'cancellation_reason_code' => null,
        ]);

        Http::assertNotSent(function ($request) use ($platform, $client) {
            return $request->url() === rtrim($platform->wp_api_url, '/') . "/clients/{$client->wp_post_id}/deactivate";
        });

        Http::assertSent(function ($request) use ($platform, $client) {
            return $request->url() === rtrim($platform->wp_api_url, '/') . "/clients/{$client->wp_post_id}/manual-payment-state"
                && $request->method() === 'POST'
                && data_get($request->data(), 'fields.billing_manual_payment_state') === 'rejected';
        });
    }

    public function test_old_submission_job_sends_latest_state_with_stable_version(): void
    {
        [$platform, $client, $oldPayment, , $oldSubmission] = $this->createManualReviewFixture(withActiveDeal: false);
        $newPayment = $oldPayment->replicate(['deal_id']);
        $newPayment->save();
        $newSubmission = $oldSubmission->replicate();
        $newSubmission->payment_id = $newPayment->id;
        $newSubmission->save();
        $oldSubmission->update(['review_decision' => 'approved']);
        $oldPayment->update(['status' => 'completed', 'reconciliation_state' => 'resolved']);
        $this->fakeWordPressForManualRejection($platform, $client, 'publish');
        $service = app(\App\Services\ManualPaymentSubmissionService::class);
        $service->synchronizeWpState($oldPayment);
        $this->travel(1)->hours();
        $service->synchronizeWpState($oldPayment);
        $sent = Http::recorded(fn ($request) => str_ends_with($request->url(), '/manual-payment-state'))->values();
        $this->assertCount(2, $sent);
        $this->assertSame($sent[0][0]->data(), $sent[1][0]->data());
        $this->assertSame('awaiting_review', data_get($sent[0][0]->data(), 'fields.billing_manual_payment_state'));
        $this->assertSame($newPayment->id, data_get($sent[0][0]->data(), 'version.payment_id'));
        $this->assertSame($newSubmission->id, data_get($sent[0][0]->data(), 'version.submission_id'));
    }

    public function test_legacy_plugin_defers_without_sending_billing_fields_to_content_editor(): void
    {
        [$platform, $client, $payment] = $this->createManualReviewFixture(withActiveDeal: false);
        Http::fake(['*' => Http::response(['supports_cursor_sync' => true])]);
        app(\App\Services\ManualPaymentSubmissionService::class)->synchronizeWpState($payment);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame('pending', $payment->fresh()->status);
    }

    /**
     * @return array{0: Platform, 1: Client, 2: Payment, 3: Deal, 4: PaymentManualSubmission}
     */
    private function createManualReviewFixture(bool $withActiveDeal): array
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://manual-reject-' . Str::lower(Str::random(6)) . '.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $product = Product::factory()->create([
            'platform_id' => $platform->id,
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 71234,
            'wp_user_id' => 61234,
            'profile_status' => 'publish',
        ]);

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'client_id' => $client->id,
            'status' => 'pending',
            'reconciliation_state' => 'manual_review',
            'payment_data' => [
                'duration_days' => 30,
            ],
        ]);

        $deal = Deal::factory()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'payment_id' => $payment->id,
            'status' => $withActiveDeal ? 'active' : 'pending',
        ]);

        $payment->forceFill([
            'deal_id' => $deal->id,
        ])->save();

        $submission = PaymentManualSubmission::query()->create([
            'payment_id' => $payment->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'product_id' => $product->id,
            'duration_key' => 'monthly',
            'manual_method_key' => 'bank_transfer',
            'activated_on_submit' => false,
            'destination_snapshot_json' => ['channel' => 'bank_transfer'],
            'instruction_snapshot_json' => ['instructions' => 'Pay and upload proof'],
            'sender_name' => 'Manual Review Tester',
            'transaction_reference' => 'MANUAL-' . Str::upper(Str::random(8)),
            'customer_note' => 'Submitted from test',
            'proof_disk' => 'local',
            'proof_path' => 'manual-payment-proofs/test.png',
            'proof_mime' => 'image/png',
            'proof_size_bytes' => 11,
        ]);

        return [$platform, $client, $payment, $deal, $submission];
    }

    private function fakeWordPressForManualRejection(Platform $platform, Client $client, string $postStatus): void
    {
        $baseUrl = rtrim($platform->wp_api_url, '/');
        $profilePayload = [
            'wp_post_id' => $client->wp_post_id,
            'wp_user_id' => $client->wp_user_id,
            'name' => $client->name,
            'post_status' => $postStatus,
            'phone' => $client->phone_normalized,
            'email' => $client->email,
            'city' => $client->city,
            'premium' => false,
            'featured' => false,
            'verified' => false,
            'main_image_url' => null,
            'last_online' => null,
        ];

        Http::fake([
            $baseUrl . '/sync/meta' => Http::response(['supports_cursor_sync' => true, 'supports_manual_payment_state' => true]),
            $baseUrl . "/clients/{$client->wp_post_id}/deactivate" => Http::response([
                'success' => true,
            ], 200),
            $baseUrl . "/clients/{$client->wp_post_id}" => Http::response($profilePayload, 200),
            $baseUrl . "/clients/{$client->wp_post_id}/manual-payment-state" => Http::response([
                'success' => true,
            ], 200),
        ]);
    }

    private function createUser(Platform $platform, string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' Reviewer',
            'email' => strtolower($role) . '-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
    }
}
