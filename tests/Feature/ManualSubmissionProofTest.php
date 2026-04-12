<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\PaymentManualSubmission;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualSubmissionProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_submission_proof_endpoint_streams_local_disk_files(): void
    {
        $platform = $this->createPlatform();
        $user = $this->createUser($platform, 'sales');
        $payment = $this->createPayment($platform);
        $path = sprintf('manual-payment-proofs/%d/%s/proof-local.png', $platform->id, Str::lower(Str::random(8)));

        Storage::disk('local')->put($path, 'local-proof');

        $submission = $this->createSubmission($payment, [
            'proof_disk' => 'local',
            'proof_path' => $path,
            'proof_mime' => 'image/png',
        ]);

        Sanctum::actingAs($user);

        $response = $this->get("/api/crm/payments/manual-submissions/{$submission->id}/proof");

        $response->assertOk();

        $this->assertStringStartsWith('image/png', (string) $response->headers->get('content-type'));
        $this->assertCacheControlIsPrivate($response);
        $this->assertSame('local-proof', $response->streamedContent());
    }

    public function test_manual_submission_proof_endpoint_serves_legacy_public_storage_urls_without_crashing(): void
    {
        $platform = $this->createPlatform();
        $user = $this->createUser($platform, 'sales');
        $payment = $this->createPayment($platform);
        $path = sprintf('manual-payment-proofs/%d/%s/proof-legacy.png', $platform->id, Str::lower(Str::random(8)));

        Storage::disk('public')->put($path, 'legacy-proof');

        $submission = $this->createSubmission($payment, [
            'proof_disk' => 'legacy_uploads',
            'proof_path' => 'https://crm.exotic-online.com/storage/' . $path,
            'proof_mime' => 'image/png',
        ]);

        Sanctum::actingAs($user);

        $response = $this->get("/api/crm/payments/manual-submissions/{$submission->id}/proof");

        $response->assertOk();

        $this->assertStringStartsWith('image/png', (string) $response->headers->get('content-type'));
        $this->assertCacheControlIsPrivate($response);
        $this->assertResponseBodyEquals($response, 'legacy-proof');
    }

    public function test_manual_submission_proof_endpoint_returns_not_found_for_unknown_disk_when_file_is_missing(): void
    {
        $platform = $this->createPlatform();
        $user = $this->createUser($platform, 'sales');
        $payment = $this->createPayment($platform);

        $submission = $this->createSubmission($payment, [
            'proof_disk' => 'legacy_uploads',
            'proof_path' => 'https://crm.exotic-online.com/storage/manual-payment-proofs/missing-proof.png',
            'proof_mime' => 'image/png',
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/crm/payments/manual-submissions/{$submission->id}/proof")
            ->assertNotFound()
            ->assertJson([
                'message' => 'Proof image could not be found.',
            ]);
    }

    private function assertResponseBodyEquals(TestResponse $response, string $expected): void
    {
        $baseResponse = $response->baseResponse;

        if (method_exists($baseResponse, 'getFile') && $baseResponse->getFile()) {
            $this->assertSame($expected, file_get_contents($baseResponse->getFile()->getPathname()));

            return;
        }

        $this->assertSame($expected, $response->streamedContent());
    }

    private function assertCacheControlIsPrivate(TestResponse $response): void
    {
        $cacheControl = strtolower((string) $response->headers->get('cache-control'));

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('no-cache', $cacheControl);
    }

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Manual Proof ' . Str::upper(Str::random(6)),
            'domain' => 'manual-proof-' . Str::lower(Str::random(6)) . '.example.test',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
            'db_host' => '127.0.0.1',
            'db_name' => 'wp_manual_proof_' . Str::lower(Str::random(6)),
            'db_user' => 'root',
            'db_pass' => 'secret',
            'db_prefix' => 'wp_',
            'wp_api_url' => 'https://manual-proof.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    private function createUser(Platform $platform, string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' User',
            'email' => strtolower($role) . '-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
    }

    private function createPayment(Platform $platform): Payment
    {
        $reference = 'MANUAL-PROOF-' . Str::upper(Str::random(6));

        return Payment::query()->create([
            'platform_id' => $platform->id,
            'phone' => '254700' . random_int(100000, 999999),
            'amount' => 1000,
            'currency' => 'KES',
            'transaction_uuid' => (string) Str::uuid(),
            'transaction_reference' => $reference,
            'reference_number' => $reference,
            'status' => 'pending',
            'purpose' => 'subscription',
            'provider_environment' => 'production',
            'payment_data' => [],
            'reconciliation_state' => 'manual_review',
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }

    private function createSubmission(Payment $payment, array $attributes = []): PaymentManualSubmission
    {
        return PaymentManualSubmission::query()->create(array_merge([
            'payment_id' => $payment->id,
            'client_id' => null,
            'platform_id' => $payment->platform_id,
            'product_id' => $payment->product_id,
            'duration_key' => 'monthly',
            'manual_method_key' => 'bank_transfer',
            'activated_on_submit' => false,
            'destination_snapshot_json' => ['channel' => 'bank_transfer'],
            'instruction_snapshot_json' => ['instructions' => 'Pay and upload proof'],
            'sender_name' => 'Jane Doe',
            'transaction_reference' => 'MANUAL-' . Str::upper(Str::random(8)),
            'customer_note' => 'Submitted from test',
            'proof_disk' => 'local',
            'proof_path' => 'manual-payment-proofs/test.png',
            'proof_mime' => 'image/png',
            'proof_size_bytes' => 11,
        ], $attributes));
    }
}
