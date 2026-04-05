<?php

namespace App\Billing\Providers\Schemas;

class MpesaStkSchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'mpesa_stk'; }
    protected function providerLabel(): string { return 'M-Pesa STK'; }
    protected function fieldDefinitions(): array
    {
        return [
            self::field('transport', 'Transport', 'select', true, false, ['django_proxy', 'direct_provider'], default: 'django_proxy', serialize: 'raw'),
            self::field('payment_service_base_url', 'Payment service base URL', 'url', false, false, serialize: 'trim_or_null'),
            self::field('organization_code', 'Organization code', 'text', true, false, default: '76'),
            self::field('callback_base_url', 'Callback base URL', 'url', false, false, serialize: 'trim_or_null'),
        ];
    }
}
