<?php

namespace App\Services;

class PaymentRecoveryUnionFind
{
    private array $parents = [];

    private array $ranks = [];

    public function makeSet(string $token): void
    {
        if (isset($this->parents[$token])) {
            return;
        }

        $this->parents[$token] = $token;
        $this->ranks[$token] = 0;
    }

    public function find(string $token): string
    {
        $this->makeSet($token);

        if ($this->parents[$token] !== $token) {
            $this->parents[$token] = $this->find($this->parents[$token]);
        }

        return $this->parents[$token];
    }

    public function union(string $left, string $right): void
    {
        $leftRoot = $this->find($left);
        $rightRoot = $this->find($right);

        if ($leftRoot === $rightRoot) {
            return;
        }

        if ($this->ranks[$leftRoot] < $this->ranks[$rightRoot]) {
            $this->parents[$leftRoot] = $rightRoot;

            return;
        }

        if ($this->ranks[$leftRoot] > $this->ranks[$rightRoot]) {
            $this->parents[$rightRoot] = $leftRoot;

            return;
        }

        $this->parents[$rightRoot] = $leftRoot;
        $this->ranks[$leftRoot]++;
    }
}
