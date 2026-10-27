<?php

namespace Tests\Unit;

use App\Models\Payment;
use App\Services\ProviderStatusQueryOrchestrator;
use PHPUnit\Framework\TestCase;

class PaymentProviderLocationRecoveryTest extends TestCase
{
    public function test_original_status_location_survives_reference_replacement(): void
    {
        $service = (new \ReflectionClass(ProviderStatusQueryOrchestrator::class))->newInstanceWithoutConstructor();
        $url = 'https://api.kopokopo.com/api/v1/incoming_payments/fixture-id';
        $p = new Payment(['transaction_reference' => 'MPESA-RECEIPT', 'raw_payload' => ['mpesa_stk' => ['location' => $url]], 'payment_data' => ['resume' => ['location' => $url]]]);
        $this->assertSame($url, $service->resolveKopoKopoLocation($p));
        $this->assertSame('MPESA-RECEIPT', $p->transaction_reference);
        $p->payment_data = ['resume' => ['location' => 'https://api.kopokopo.com/api/v1/incoming_payments/another-id']];
        $this->expectException(\RuntimeException::class);
        $service->resolveKopoKopoLocation($p);
    }
}
