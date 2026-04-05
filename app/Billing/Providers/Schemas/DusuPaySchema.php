<?php

namespace App\Billing\Providers\Schemas;

class DusuPaySchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'dusupay'; }
    protected function providerLabel(): string { return 'DusuPay'; }
    protected function fieldDefinitions(): array
    {
        return [
            self::field('merchant_id', 'Merchant ID', 'text', true, true),
            self::field('api_key', 'API key', 'secret', true, true),
            self::field('public_key', 'Public key', 'text', true),
            self::field('callback_base_url', 'Callback base URL', 'url', false, false, serialize: 'trim_or_null'),
        ];
    }
}
