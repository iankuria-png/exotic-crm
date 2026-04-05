<?php

namespace App\Billing\Providers\Schemas;

class PawaPaySchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'pawapay'; }
    protected function providerLabel(): string { return 'pawaPay'; }
    protected function fieldDefinitions(): array
    {
        return [
            self::field('api_key', 'API key', 'secret', true, true),
            self::field('base_url', 'Base URL', 'url', false, false, serialize: 'trim_or_null'),
            self::field('callback_base_url', 'Callback base URL', 'url', false, false, serialize: 'trim_or_null'),
        ];
    }
}
