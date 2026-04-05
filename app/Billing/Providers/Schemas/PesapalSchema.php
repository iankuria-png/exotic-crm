<?php

namespace App\Billing\Providers\Schemas;

class PesapalSchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'pesapal'; }
    protected function providerLabel(): string { return 'Pesapal'; }
    protected function fieldDefinitions(): array
    {
        return [
            self::field('consumer_key', 'Consumer key', 'text', true, true, configuredFlag: 'consumer_key_configured'),
            self::field('consumer_secret', 'Consumer secret', 'secret', true, true, configuredFlag: 'consumer_secret_configured'),
            self::field('ipn_id', 'IPN ID', 'text', true),
        ];
    }
}
