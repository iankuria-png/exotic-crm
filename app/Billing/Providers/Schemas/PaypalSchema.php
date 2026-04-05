<?php

namespace App\Billing\Providers\Schemas;

class PaypalSchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'paypal'; }
    protected function providerLabel(): string { return 'PayPal'; }
    protected function fieldDefinitions(): array
    {
        return [
            self::field('client_id', 'Client ID', 'text', true, true),
            self::field('client_secret', 'Client secret', 'secret', true, true),
            self::field('webhook_id', 'Webhook ID', 'text'),
        ];
    }
}
