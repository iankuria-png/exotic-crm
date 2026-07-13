<?php

namespace App\Services\Sms;

interface SmsProviderInterface
{
    public function id(): string;

    public function label(): string;

    public function credentialFields(): array;

    public function configured(array $config): bool;

    public function send(string $phone, string $message, array $config, array $context = []): array;
}
