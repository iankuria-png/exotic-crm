<?php

namespace App\Billing\Providers\Schemas;

class NowPaymentsSchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'nowpayments'; }
    protected function providerLabel(): string { return 'NOWPayments'; }
    protected function fieldDefinitions(): array
    {
        return [
            self::field('api_key', 'API key', 'secret', true, true),
            self::field('ipn_secret', 'IPN secret', 'secret', false, true),
            self::field('callback_base_url', 'Callback base URL', 'url', false, false, serialize: 'trim_or_null'),
        ];
    }

    protected function environments(): array
    {
        return ['production'];
    }
}
