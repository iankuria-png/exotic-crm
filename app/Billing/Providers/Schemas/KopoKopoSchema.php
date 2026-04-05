<?php

namespace App\Billing\Providers\Schemas;

class KopoKopoSchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'kopokopo'; }
    protected function providerLabel(): string { return 'KopoKopo'; }
    protected function fieldDefinitions(): array
    {
        return [
            self::field('base_url', 'Base URL', 'url', true, false, serialize: 'trim_or_null'),
            self::field('client_id', 'Client ID', 'text', true, true),
            self::field('client_secret', 'Client secret', 'secret', true, true),
            self::field('api_key', 'API key', 'secret', true, true),
            self::field('till_number', 'Till number', 'text', true),
            self::field('callback_base_url', 'Callback base URL', 'url', false, false, serialize: 'trim_or_null'),
        ];
    }
}
