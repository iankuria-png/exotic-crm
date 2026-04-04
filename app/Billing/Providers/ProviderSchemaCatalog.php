<?php

namespace App\Billing\Providers;

use App\Billing\Providers\Schemas\StaticProviderCredentialSchema;

final class ProviderSchemaCatalog
{
    /**
     * @return list<StaticProviderCredentialSchema>
     */
    public static function schemas(): array
    {
        return [
            new StaticProviderCredentialSchema('pesapal', 'Pesapal', [
                self::field('consumer_key', 'Consumer key', 'text', true, true, configuredFlag: 'consumer_key_configured'),
                self::field('consumer_secret', 'Consumer secret', 'secret', true, true, configuredFlag: 'consumer_secret_configured'),
                self::field('ipn_id', 'IPN ID', 'text', true),
            ]),
            new StaticProviderCredentialSchema('paystack', 'Paystack', [
                self::field('public_key', 'Public key', 'text', true, false, configuredFlag: 'public_key_configured'),
                self::field('secret_key', 'Secret key', 'secret', true, true, configuredFlag: 'secret_key_configured'),
            ]),
            new StaticProviderCredentialSchema('mpesa_stk', 'M-Pesa STK', [
                self::field('transport', 'Transport', 'select', true, false, ['django_proxy', 'direct_provider'], default: 'django_proxy', serialize: 'raw'),
                self::field('payment_service_base_url', 'Payment service base URL', 'url', serialize: 'trim_or_null'),
                self::field('organization_code', 'Organization code', 'text', true, false, default: '76'),
                self::field('callback_base_url', 'Callback base URL', 'url', serialize: 'trim_or_null'),
            ]),
            new StaticProviderCredentialSchema('daraja', 'Safaricom Daraja', [
                self::field('consumer_key', 'Consumer key', 'text', true, true),
                self::field('consumer_secret', 'Consumer secret', 'secret', true, true),
                self::field('short_code', 'Short code', 'text', true),
                self::field('passkey', 'Passkey', 'secret', true, true),
                self::field('callback_base_url', 'Callback base URL', 'url', true, serialize: 'trim_or_null'),
            ]),
            new StaticProviderCredentialSchema('kopokopo', 'KopoKopo', [
                self::field('base_url', 'Base URL', 'url', true, serialize: 'trim_or_null'),
                self::field('client_id', 'Client ID', 'text', true, true),
                self::field('client_secret', 'Client secret', 'secret', true, true),
                self::field('api_key', 'API key', 'secret', true, true),
                self::field('till_number', 'Till number', 'text', true),
                self::field('callback_base_url', 'Callback base URL', 'url', serialize: 'trim_or_null'),
            ]),
            new StaticProviderCredentialSchema('pawapay', 'pawaPay', [
                self::field('api_key', 'API key', 'secret', true, true),
                self::field('base_url', 'Base URL', 'url', serialize: 'trim_or_null'),
                self::field('callback_base_url', 'Callback base URL', 'url', serialize: 'trim_or_null'),
            ]),
            new StaticProviderCredentialSchema('elemitech', 'ElemiTech', [
                self::field('merchant_id', 'Merchant ID', 'text', true, true),
                self::field('public_key', 'Public key', 'text', true),
                self::field('secret_key', 'Secret key', 'secret', true, true),
                self::field('callback_base_url', 'Callback base URL', 'url', serialize: 'trim_or_null'),
            ]),
            new StaticProviderCredentialSchema('dusupay', 'DusuPay', [
                self::field('merchant_id', 'Merchant ID', 'text', true, true),
                self::field('api_key', 'API key', 'secret', true, true),
                self::field('public_key', 'Public key', 'text', true),
                self::field('callback_base_url', 'Callback base URL', 'url', serialize: 'trim_or_null'),
            ]),
            new StaticProviderCredentialSchema('nowpayments', 'NOWPayments', [
                self::field('api_key', 'API key', 'secret', true, true),
                self::field('ipn_secret', 'IPN secret', 'secret', false, true),
                self::field('callback_base_url', 'Callback base URL', 'url', serialize: 'trim_or_null'),
            ], ['production']),
            new StaticProviderCredentialSchema('paypal', 'PayPal', [
                self::field('client_id', 'Client ID', 'text', true, true),
                self::field('client_secret', 'Client secret', 'secret', true, true),
                self::field('webhook_id', 'Webhook ID', 'text'),
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function field(
        string $key,
        string $label,
        string $type,
        bool $required = false,
        bool $sensitive = false,
        array $options = [],
        ?string $configuredFlag = null,
        ?string $default = null,
        ?string $serialize = null
    ): array {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'required' => $required,
            'sensitive' => $sensitive,
            'placeholder' => $label,
            'options' => $options === [] ? null : $options,
            'configured_flag' => $configuredFlag,
            'default' => $default,
            'serialize' => $serialize ?? match ($type) {
                'url' => 'trim_or_null',
                'select' => 'raw',
                default => 'trim',
            },
        ], static fn ($value) => $value !== null);
    }
}
