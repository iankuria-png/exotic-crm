<?php

namespace App\Billing\Providers\Schemas;

class ElemiTechSchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'elemitech'; }
    protected function providerLabel(): string { return 'ElemiTech'; }
    protected function fieldDefinitions(): array
    {
        return [
            self::field('merchant_id', 'Merchant ID', 'text', true, true),
            self::field('public_key', 'Public key', 'text', true),
            self::field('secret_key', 'Secret key', 'secret', true, true),
            self::field('callback_base_url', 'Callback base URL', 'url', false, false, serialize: 'trim_or_null'),
        ];
    }
}
