<?php

namespace App\Billing\Providers\Schemas;

class StaticPaymentLinkSchema extends AbstractProviderCredentialSchema
{
    protected function key(): string { return 'payment_link_static'; }
    protected function providerLabel(): string { return 'Static Payment Link URL'; }

    protected function fieldDefinitions(): array
    {
        return [
            self::field('url', 'Payment link URL', 'url', required: true, sensitive: false),
            self::field('base_url', 'Base URL', 'url', required: false, sensitive: false),
            self::field('path', 'Path suffix', 'text', required: false, sensitive: false),
            self::field('label', 'Display label', 'text', required: false, sensitive: false),
        ];
    }

    protected function environments(): array
    {
        return ['sandbox', 'production'];
    }
}
