<?php

namespace App\Services\Forecast;

use App\Services\Ai\AiGateway;
use Throwable;

class ForecastNarrativeService
{
    public function __construct(
        private readonly AiGateway $aiGateway
    ) {}

    public function generate(string $kind, array $payload): array
    {
        $system = 'You summarize sales forecast figures. Never invent arithmetic; only explain the provided JSON.';
        $user = json_encode(['kind' => $kind, 'payload' => $payload], JSON_THROW_ON_ERROR);

        try {
            $result = $this->aiGateway->generate('sales_forecast_'.$kind, $system, $user, [
                'temperature' => 0.2,
                'max_tokens' => 450,
            ]);

            return [
                'state' => 'ready',
                'text' => $result->text(),
            ];
        } catch (Throwable $exception) {
            return [
                'state' => 'unavailable',
                'text' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
