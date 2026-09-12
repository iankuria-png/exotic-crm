<?php

namespace App\Services\Mcp;

class OpaqueEntityLocator
{
    public function payment(int $id): string
    {
        return 'pay_'.rtrim(strtr(base64_encode(hash_hmac('sha256', (string) $id, (string) config('app.key'), true)), '+/', '-_'), '=');
    }

    public function resolvesPayment(string $locator, int $id): bool
    {
        return hash_equals($this->payment($id), $locator);
    }
}
