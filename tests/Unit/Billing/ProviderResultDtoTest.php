<?php

namespace Tests\Unit\Billing;

use App\Billing\Support\ProviderInitResult;
use App\Billing\Support\ProviderStatusResult;
use App\Billing\Support\ProviderWebhookParseResult;
use Tests\TestCase;

class ProviderResultDtoTest extends TestCase
{
    public function test_provider_result_dtos_normalize_their_public_shape(): void
    {
        $init = new ProviderInitResult(
            providerKey: 'paystack',
            status: 'pending_action',
            providerReference: 'psk_123',
            redirectUrl: 'https://checkout.example.test/redirect',
            message: 'Redirect customer to provider checkout.',
            raw: ['type' => 'redirect']
        );
        $status = new ProviderStatusResult(
            providerKey: 'kopokopo',
            status: 'completed',
            providerReference: 'k2_123',
            message: 'Payment settled.',
            terminal: true,
            raw: ['status' => 'success']
        );
        $webhook = new ProviderWebhookParseResult(
            providerKey: 'nowpayments',
            accepted: true,
            eventType: 'payment_status_changed',
            eventId: 'evt_123',
            providerReference: 'np_123',
            status: 'pending_confirmation',
            message: 'Awaiting chain confirmation.',
            raw: ['event' => 'payment_status_changed']
        );

        $this->assertTrue($init->isSuccessful());
        $this->assertTrue($status->isSuccessful());
        $this->assertSame('pending_action', $init->toArray()['status']);
        $this->assertSame('completed', $status->toArray()['status']);
        $this->assertTrue($webhook->toArray()['accepted']);
        $this->assertSame('evt_123', $webhook->toArray()['event_id']);
    }
}
