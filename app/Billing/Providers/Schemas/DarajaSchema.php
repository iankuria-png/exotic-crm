<?php

namespace App\Billing\Providers\Schemas;

class DarajaSchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'daraja'; }
    protected function providerLabel(): string { return 'Safaricom Daraja'; }
    protected function fieldDefinitions(): array
    {
        return [
            self::field('consumer_key', 'Consumer key', 'text', true, true),
            self::field('consumer_secret', 'Consumer secret', 'secret', true, true),
            self::field('short_code', 'Short code', 'text', true),
            self::field('passkey', 'Passkey', 'secret', true, true),
            self::field('callback_base_url', 'Callback base URL', 'url', true, false, serialize: 'trim_or_null'),
        ];
    }
}
