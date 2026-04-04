<?php

namespace Tests\Unit\Billing;

use App\Billing\Contracts\ProviderCredentialSchemaRegistry as ProviderCredentialSchemaRegistryContract;
use Tests\TestCase;

class ProviderSchemaRegistryTest extends TestCase
{
    public function test_provider_schema_registry_exposes_catalogued_schema_metadata(): void
    {
        $registry = $this->app->make(ProviderCredentialSchemaRegistryContract::class);

        $this->assertTrue($registry->has('paystack'));
        $this->assertTrue($registry->has('daraja'));
        $this->assertTrue($registry->has('nowpayments'));
        $this->assertFalse($registry->has('unknown'));

        $paystack = $registry->find('paystack');
        $nowPayments = $registry->find('nowpayments');

        $this->assertSame(['sandbox', 'production'], $paystack?->supportedEnvironments());
        $this->assertSame('public_key', $paystack?->fields()[0]['key']);
        $this->assertSame(['production'], $nowPayments?->supportedEnvironments());
        $this->assertSame('api_key', $nowPayments?->fields()[0]['key']);
    }
}
