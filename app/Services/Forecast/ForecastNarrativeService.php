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
        $system = <<<'PROMPT'
        You brief a CEO on a revenue scenario. Write 2-4 short sentences of plain prose.

        Say what the scenario asks the business to do in operational terms - how many more
        payments recovered, clients converted, subscriptions renewed - and name which single
        lever carries most of the upside. If the upside is small relative to the baseline,
        say so plainly.

        Hard rules: never restate the input as a list, never use headings, bullets, JSON or
        markdown, never mention that you were given data or in what format, and never compute
        a figure that is not already in the input. Round money to whole units. If a figure is
        missing, leave it out rather than guessing.
        PROMPT;

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
