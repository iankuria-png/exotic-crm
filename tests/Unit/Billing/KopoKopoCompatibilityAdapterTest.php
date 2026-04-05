<?php

namespace Tests\Unit\Billing;

use App\Billing\Providers\KopoKopo\KopoKopoCompatibilityAdapter;
use App\Services\KopokopoService;
use Mockery;
use Tests\TestCase;

class KopoKopoCompatibilityAdapterTest extends TestCase
{
    public function test_initiate_stk_push_delegates_to_kopokopo_service(): void
    {
        $service = Mockery::mock(KopokopoService::class);
        $service->shouldReceive('initiateStkPush')
            ->once()
            ->with(
                '254700000111',
                900.0,
                'https://crm.test/api/billing/mpesa/callback',
                ['payment_id' => 42],
                ['base_url' => 'https://profile.kopokopo.test']
            )
            ->andReturn(['status' => 'success']);

        $adapter = new KopoKopoCompatibilityAdapter($service);

        $result = $adapter->initiateStkPush(
            '254700000111',
            900.0,
            'https://crm.test/api/billing/mpesa/callback',
            ['payment_id' => 42],
            ['base_url' => 'https://profile.kopokopo.test']
        );

        $this->assertSame('success', $result['status']);
    }

    public function test_handle_webhook_delegates_to_kopokopo_service(): void
    {
        $service = Mockery::mock(KopokopoService::class);
        $service->shouldReceive('handleWebhook')
            ->once()
            ->with('{"topic":"buygoods_transaction_received"}', 'mock-signature')
            ->andReturn(['status' => 'success']);

        $adapter = new KopoKopoCompatibilityAdapter($service);

        $result = $adapter->handleWebhook('{"topic":"buygoods_transaction_received"}', 'mock-signature');

        $this->assertSame('success', $result['status']);
    }
}
