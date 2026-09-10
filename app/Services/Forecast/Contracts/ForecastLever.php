<?php

namespace App\Services\Forecast\Contracts;

interface ForecastLever
{
    public function key(): string;

    public function label(): string;

    public function supportedModes(): array;

    public function apply(array $baseline, mixed $input, string $mode): array;

    public function yieldPerUnit(array $baseline): float;
}
