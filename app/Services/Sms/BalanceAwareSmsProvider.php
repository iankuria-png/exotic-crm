<?php

namespace App\Services\Sms;

/**
 * Providers that can report the account's remaining balance/credits via API.
 * Providers without a balance endpoint simply don't implement this — the UI
 * then shows "check on the provider portal".
 */
interface BalanceAwareSmsProvider
{
    /**
     * @return array{amount: float, currency: string, raw: string}|null
     *         null when the balance can't be resolved (misconfig, API error).
     */
    public function fetchBalance(array $config): ?array;
}
