<?php

namespace App\Services\AutoPush;

use App\Models\AutoPushPlan;
use App\Models\Client;
use App\Services\Ai\AiGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AutoPushMessageService
{
    public function __construct(
        private readonly AiGateway $aiGateway,
    ) {
    }

    public function renderSeed(AutoPushPlan $plan, Client $client, array $profileFacts = []): string
    {
        $phrases = collect((array) data_get($plan->message_strategy, 'seed_phrases', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values();

        $phrase = $phrases->get($phrases->isEmpty() ? 0 : ((int) $client->id % $phrases->count()), 'Meet someone new nearby');
        $rendered = str_replace(
            ['{{name}}', '{{city}}', '{{age}}'],
            [
                trim((string) ($client->name ?: '')),
                trim((string) ($client->city ?: '')),
                trim((string) ($profileFacts['age'] ?? '')),
            ],
            $phrase
        );

        $rendered = preg_replace('/\s+/', ' ', str_replace([' ,', ' .'], [',', '.'], (string) $rendered)) ?? '';
        $rendered = str_replace(['  ,', '  .'], [',', '.'], $rendered);
        $rendered = trim(preg_replace('/\{\{[^}]+\}\}/', '', $rendered) ?? '');

        return $this->truncate($rendered, $this->maxChars($plan));
    }

    /**
     * @return array{message:string,source:string,cost_usd:float}
     */
    public function generateMessage(AutoPushPlan $plan, Client $client, array $profileFacts = []): array
    {
        $mode = (string) data_get($plan->message_strategy, 'mode', 'hybrid');
        if ($mode === 'seed') {
            return [
                'message' => $this->renderSeed($plan, $client, $profileFacts),
                'source' => 'seed',
                'cost_usd' => 0.0,
            ];
        }

        $maxChars = $this->maxChars($plan);
        $seedPhrases = collect((array) data_get($plan->message_strategy, 'seed_phrases', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->take(2)
            ->values()
            ->all();

        $country = trim((string) ($plan->platform?->country ?? 'the target market'));
        $tone = trim((string) data_get($plan->message_strategy, 'tone', ''));
        $temperament = trim((string) data_get($plan->message_strategy, 'temperament', ''));
        $system = trim(implode("\n", array_filter([
            "Write one short push-notification line for an adult directory profile in {$country}.",
            'Keep it flirty but not explicit.',
            'Use one or two sentences max.',
            "Stay under {$maxChars} characters.",
            'Do not use emoji.',
            $tone !== '' ? "Tone: {$tone}." : null,
            $temperament !== '' ? "Temperament: {$temperament}." : null,
            $seedPhrases !== [] ? 'Style references: ' . implode(' | ', $seedPhrases) : null,
        ])));

        $facts = array_filter([
            'Name' => $client->name,
            'City' => $client->city,
            'Plan' => $client->plan_label,
            'Age' => $profileFacts['age'] ?? null,
        ], fn ($value) => $value !== null && trim((string) $value) !== '');
        $user = collect($facts)->map(fn ($value, $label) => "{$label}: {$value}")->implode("\n");

        try {
            $result = $this->aiGateway->generate('auto_push_message', $system, $user, [
                'max_tokens' => 120,
            ]);

            return [
                'message' => $this->truncate(trim($result->text()), $maxChars),
                'source' => 'ai',
                'cost_usd' => (float) ($result->interaction->est_cost_usd ?? 0),
            ];
        } catch (\Throwable $exception) {
            Log::warning('auto_push.message_generation_failed', [
                'plan_id' => (int) $plan->id,
                'client_id' => (int) $client->id,
                'error' => $exception->getMessage(),
            ]);

            return [
                'message' => $this->renderSeed($plan, $client, $profileFacts),
                'source' => 'seed',
                'cost_usd' => 0.0,
            ];
        }
    }

    private function maxChars(AutoPushPlan $plan): int
    {
        return max(40, (int) data_get($plan->message_strategy, 'max_chars', 120));
    }

    private function truncate(string $message, int $maxChars): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'Meet someone new nearby.';
        }

        return Str::limit($message, $maxChars, '');
    }
}
