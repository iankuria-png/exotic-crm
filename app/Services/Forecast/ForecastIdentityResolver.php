<?php

namespace App\Services\Forecast;

use App\Models\Client;
use App\Models\Payment;
use App\Services\PaymentRecoveryUnionFind;
use App\Services\Payments\PaymentIdentityTokenizer;

class ForecastIdentityResolver
{
    private PaymentRecoveryUnionFind $unionFind;

    private int $bridgeMergeCount = 0;

    public function __construct(
        private readonly PaymentIdentityTokenizer $tokenizer
    ) {
        $this->unionFind = new PaymentRecoveryUnionFind;
    }

    public function seedPayment(Payment $payment, ?string $phonePrefix = null): void
    {
        $this->register($this->tokenizer->bridgeTokens($payment, $phonePrefix));
    }

    public function seedClient(Client $client, ?string $phonePrefix = null): void
    {
        $this->register($this->tokenizer->clientTokens($client, $phonePrefix));
    }

    public function rootForPayment(Payment $payment, ?string $phonePrefix = null): string
    {
        $tokens = $this->tokenizer->bridgeTokens($payment, $phonePrefix);
        $this->register($tokens);

        return $this->unionFind->find($tokens[0]);
    }

    public function rootForClient(Client $client, ?string $phonePrefix = null): string
    {
        $tokens = $this->tokenizer->clientTokens($client, $phonePrefix);
        $this->register($tokens);

        return $this->unionFind->find($tokens[0]);
    }

    public function mergedCount(): int
    {
        return $this->bridgeMergeCount;
    }

    private function register(array $tokens): void
    {
        foreach ($tokens as $token) {
            $this->unionFind->makeSet($token);
        }

        $first = $tokens[0] ?? null;
        if ($first === null) {
            return;
        }

        foreach (array_slice($tokens, 1) as $token) {
            $left = $this->unionFind->find($first);
            $right = $this->unionFind->find($token);
            if ($left !== $right) {
                $this->bridgeMergeCount++;
            }

            $this->unionFind->union($first, $token);
        }
    }
}
