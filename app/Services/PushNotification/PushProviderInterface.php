<?php

namespace App\Services\PushNotification;

interface PushProviderInterface
{
    public function id(): string;

    public function configured(array $config): bool;

    public function send(array $notification, array $config, array $context = []): array;

    public function getStatus(string $providerNotificationId, array $config): ?array;

    /**
     * @return array{total:int, active:int}|null
     */
    public function getSubscriberCount(array $config): ?array;
}
