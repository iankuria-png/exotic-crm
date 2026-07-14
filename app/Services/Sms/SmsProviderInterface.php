<?php

namespace App\Services\Sms;

interface SmsProviderInterface
{
    public function id(): string;

    /**
     * Human-readable provider name shown in the settings UI.
     */
    public function label(): string;

    /**
     * Metadata describing each credential field the provider needs. Drives the
     * dynamic settings form, secret masking, and credential merging. Each entry:
     *   ['key' => string, 'label' => string, 'type' => 'text|url|password',
     *    'required' => bool, 'secret'? => bool, 'default'? => string]
     *
     * @return array<int, array<string, mixed>>
     */
    public function credentialFields(): array;

    public function configured(array $config): bool;

    public function send(string $phone, string $message, array $config, array $context = []): array;
}
