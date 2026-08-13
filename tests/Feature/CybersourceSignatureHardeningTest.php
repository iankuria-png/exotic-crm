<?php

namespace Tests\Feature;

use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CybersourceSignatureHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cybersource.secret_key', 'cyber-test-secret');
    }

    public function test_unsigned_cybersource_notification_cannot_mutate_payment(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'pending',
            'completed_at' => null,
            'transaction_uuid' => 'cyber-pending-001',
            'reference_number' => 'REF-CYBER-001',
        ]);

        $this->post('/api/cybersource/notifications', [
            'decision' => 'ACCEPT',
            'transaction_uuid' => 'cyber-pending-001',
            'req_reference_number' => 'REF-CYBER-001',
            'message' => 'Forged approval',
        ])
            ->assertStatus(401)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Invalid payment notification');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_signed_cybersource_notification_can_mark_payment_failed(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'pending',
            'completed_at' => null,
            'transaction_uuid' => 'cyber-decline-001',
            'reference_number' => 'REF-CYBER-DECLINE',
        ]);

        $this->post('/api/payment/notification', $this->signedCybersourcePayload([
            'decision' => 'DECLINE',
            'transaction_uuid' => 'cyber-decline-001',
            'req_reference_number' => 'REF-CYBER-DECLINE',
            'req_amount' => (string) $payment->amount,
            'req_currency' => $payment->currency,
            'reason' => 'Issuer declined the card.',
        ]))
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('payment_status', 'failed');

        $payment->refresh();

        $this->assertSame('failed', $payment->status);
        $this->assertSame('Issuer declined the card.', $payment->failure_reason);
    }

    public function test_signed_cybersource_notification_with_mismatched_amount_is_rejected(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'pending',
            'completed_at' => null,
            'amount' => 2500,
            'currency' => 'KES',
            'transaction_uuid' => 'cyber-mismatch-001',
            'reference_number' => 'REF-CYBER-MISMATCH',
        ]);

        $this->post('/api/cybersource/notifications', $this->signedCybersourcePayload([
            'decision' => 'DECLINE',
            'transaction_uuid' => 'cyber-mismatch-001',
            'req_reference_number' => 'REF-CYBER-MISMATCH',
            'req_amount' => '2400',
            'req_currency' => 'KES',
            'reason' => 'Mismatched amount should not mutate.',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'CyberSource amount does not match payment.');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_cybersource_signed_field_names_cannot_include_signature(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'pending',
            'completed_at' => null,
            'provider_key' => 'cybersource',
            'transaction_uuid' => 'cyber-signed-fields-001',
            'reference_number' => 'REF-CYBER-SIGNED-FIELDS',
        ]);

        $this->post('/api/cybersource/notifications', [
            'decision' => 'ACCEPT',
            'transaction_uuid' => 'cyber-signed-fields-001',
            'req_reference_number' => 'REF-CYBER-SIGNED-FIELDS',
            'request_id' => 'REQ-CYBER-SIGNED-FIELDS',
            'signed_field_names' => 'decision,transaction_uuid,req_reference_number,request_id,signature,signed_field_names',
            'signature' => 'malformed',
        ])
            ->assertStatus(401)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Invalid payment notification');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_signed_cybersource_accept_rejects_non_cybersource_payment(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'pending',
            'completed_at' => null,
            'provider_key' => 'paystack',
            'transaction_uuid' => 'cyber-provider-001',
            'reference_number' => 'REF-CYBER-PROVIDER',
        ]);

        $this->post('/api/cybersource/notifications', $this->signedCybersourcePayload([
            'decision' => 'ACCEPT',
            'transaction_uuid' => 'cyber-provider-001',
            'req_reference_number' => 'REF-CYBER-PROVIDER',
            'request_id' => 'REQ-CYBER-PROVIDER',
            'req_amount' => (string) $payment->amount,
            'req_currency' => $payment->currency,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Payment is not eligible for CyberSource mutation.');

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_signed_cybersource_accept_rejects_terminal_payment(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'failed',
            'completed_at' => null,
            'provider_key' => 'cybersource',
            'transaction_uuid' => 'cyber-terminal-001',
            'reference_number' => 'REF-CYBER-TERMINAL',
        ]);

        $this->post('/api/cybersource/notifications', $this->signedCybersourcePayload([
            'decision' => 'ACCEPT',
            'transaction_uuid' => 'cyber-terminal-001',
            'req_reference_number' => 'REF-CYBER-TERMINAL',
            'request_id' => 'REQ-CYBER-TERMINAL',
            'req_amount' => (string) $payment->amount,
            'req_currency' => $payment->currency,
        ]))
            ->assertStatus(422)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', 'Payment is not eligible for CyberSource completion.');

        $this->assertSame('failed', $payment->fresh()->status);
    }

    public function test_signed_cybersource_accept_completes_eligible_payment(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'pending',
            'completed_at' => null,
            'provider_key' => 'cybersource',
            'transaction_uuid' => 'cyber-accept-001',
            'transaction_reference' => null,
            'reference_number' => 'REF-CYBER-ACCEPT',
        ]);

        $this->post('/api/cybersource/notifications', $this->signedCybersourcePayload([
            'decision' => 'ACCEPT',
            'transaction_uuid' => 'cyber-accept-001',
            'req_reference_number' => 'REF-CYBER-ACCEPT',
            'request_id' => 'REQ-CYBER-ACCEPT',
            'req_amount' => (string) $payment->amount,
            'req_currency' => $payment->currency,
        ]))
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('payment_status', 'completed');

        $payment->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertSame('REQ-CYBER-ACCEPT', $payment->transaction_reference);
    }

    public function test_signed_cybersource_decline_does_not_downgrade_completed_payment(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'completed',
            'completed_at' => now(),
            'provider_key' => 'cybersource',
            'transaction_uuid' => 'cyber-stale-decline-001',
            'reference_number' => 'REF-CYBER-STALE-DECLINE',
        ]);

        $this->post('/api/cybersource/notifications', $this->signedCybersourcePayload([
            'decision' => 'DECLINE',
            'transaction_uuid' => 'cyber-stale-decline-001',
            'req_reference_number' => 'REF-CYBER-STALE-DECLINE',
            'request_id' => 'REQ-CYBER-STALE-DECLINE',
            'req_amount' => (string) $payment->amount,
            'req_currency' => $payment->currency,
            'reason' => 'Stale decline',
        ]))
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('payment_status', 'completed');

        $payment->refresh();

        $this->assertSame('completed', $payment->status);
        $this->assertNull($payment->failure_reason);
    }

    public function test_unsigned_browser_response_does_not_mutate_payment(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'pending',
            'completed_at' => null,
            'transaction_uuid' => 'cyber-browser-001',
            'reference_number' => 'REF-CYBER-BROWSER',
        ]);

        $this->post('/response', [
            'decision' => 'DECLINE',
            'transaction_uuid' => 'cyber-browser-001',
            'req_reference_number' => 'REF-CYBER-BROWSER',
            'reason' => 'Forged browser decline.',
        ])->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_browser_cancel_only_mutates_when_signed(): void
    {
        $unsignedPayment = Payment::factory()->create([
            'status' => 'pending',
            'completed_at' => null,
        ]);

        $this->post('/cancel', [
            'payment_id' => (string) $unsignedPayment->id,
        ])->assertOk();

        $this->assertSame('pending', $unsignedPayment->fresh()->status);

        $signedPayment = Payment::factory()->create([
            'status' => 'pending',
            'completed_at' => null,
        ]);

        $this->post('/cancel', $this->signedCybersourcePayload([
            'payment_id' => (string) $signedPayment->id,
        ]))->assertOk();

        $this->assertSame('canceled', $signedPayment->fresh()->status);
    }

    /**
     * CyberSource Secure Acceptance signs comma-joined `name=value` pairs in
     * the exact order listed by signed_field_names.
     */
    private function signedCybersourcePayload(array $fields): array
    {
        $signedFieldNames = array_merge(array_keys($fields), ['signed_field_names']);
        $payload = $fields;
        $payload['signed_field_names'] = implode(',', $signedFieldNames);

        $dataToSign = implode(',', array_map(
            fn (string $field): string => $field.'='.(string) $payload[$field],
            $signedFieldNames
        ));

        $payload['signature'] = base64_encode(hash_hmac(
            'sha256',
            $dataToSign,
            (string) config('services.cybersource.secret_key'),
            true
        ));

        return $payload;
    }
}
