<?php

namespace Tests\Unit\Billing;

use App\Billing\Contracts\ProviderCredentialSchemaRegistry as ProviderCredentialSchemaRegistryContract;
use Tests\TestCase;

class ProviderSchemaRegistryTest extends TestCase
{
    public function test_provider_schema_registry_exposes_labeled_schema_metadata_for_active_and_future_providers(): void
    {
        $registry = $this->app->make(ProviderCredentialSchemaRegistryContract::class);

        $this->assertTrue($registry->has('pesapal'));
        $this->assertTrue($registry->has('daraja'));
        $this->assertTrue($registry->has('nowpayments'));
        $this->assertSame('Paystack', $registry->find('paystack')?->label());
        $this->assertSame(['production'], $registry->find('nowpayments')?->supportedEnvironments());
        $this->assertSame('consumer_secret_configured', $registry->find('pesapal')?->fields()[1]['configured_flag'] ?? null);
        $this->assertSame('select', $registry->find('mpesa_stk')?->fields()[0]['type'] ?? null);
    }
}
