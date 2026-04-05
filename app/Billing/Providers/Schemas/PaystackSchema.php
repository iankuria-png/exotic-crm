<?php

namespace App\Billing\Providers\Schemas;

class PaystackSchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'paystack'; }
    protected function providerLabel(): string { return 'Paystack'; }
    protected function fieldDefinitions(): array
    {
        return [
            self::field('public_key', 'Public key', 'text', true, false, configuredFlag: 'public_key_configured'),
            self::field('secret_key', 'Secret key', 'secret', true, true, configuredFlag: 'secret_key_configured'),
        ];
    }
}
