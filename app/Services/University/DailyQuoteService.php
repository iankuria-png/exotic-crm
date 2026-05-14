<?php

namespace App\Services\University;

use App\Models\University\DailyQuote;
use Illuminate\Support\Carbon;

class DailyQuoteService
{
    private const QUOTES = [
        ['quote' => 'Akili ni mali.', 'author' => 'Wahenga', 'source_label' => 'Swahili proverb', 'category' => 'mindset'],
        ['quote' => 'Haraka haraka haina baraka.', 'author' => 'Wahenga', 'source_label' => 'Swahili proverb', 'category' => 'discipline'],
        ['quote' => 'Measure twice, promise once.', 'author' => 'Exotic Online Playbook', 'source_label' => 'Sales principle', 'category' => 'sales'],
        ['quote' => 'The next best action is the one the customer can understand now.', 'author' => 'Exotic Online University', 'source_label' => 'CRM habit', 'category' => 'service'],
        ['quote' => 'Clarity beats pressure when the customer is unsure.', 'author' => 'Exotic Online University', 'source_label' => 'Renewals', 'category' => 'renewals'],
        ['quote' => 'A clean note today saves a rescue tomorrow.', 'author' => 'Exotic Online University', 'source_label' => 'CRM discipline', 'category' => 'operations'],
        ['quote' => 'Ask one better question before offering one bigger discount.', 'author' => 'Exotic Online University', 'source_label' => 'Objection handling', 'category' => 'sales'],
        ['quote' => 'The customer should leave the chat knowing the next step and the reason.', 'author' => 'Exotic Online University', 'source_label' => 'Customer success', 'category' => 'service'],
    ];

    public function quoteFor(Carbon $date): array
    {
        $stored = DailyQuote::query()
            ->whereDate('quote_date', $date->toDateString())
            ->first();

        if ($stored) {
            return $this->serialize($stored, true);
        }

        return array_merge($this->defaultQuoteFor($date), [
            'quote_date' => $date->toDateString(),
            'is_custom' => false,
        ]);
    }

    public function suggestion(?string $excludeQuote = null): array
    {
        $pool = collect(self::QUOTES)
            ->reject(fn (array $quote) => $excludeQuote && trim($quote['quote']) === trim($excludeQuote))
            ->values();

        return $pool->isEmpty() ? self::QUOTES[0] : $pool->random();
    }

    public function submitForTomorrow(array $payload, int $userId): array
    {
        $tomorrow = now()->addDay()->toDateString();

        $quote = DailyQuote::updateOrCreate(
            ['quote_date' => $tomorrow],
            [
                'quote' => $payload['quote'],
                'author' => $payload['author'] ?? null,
                'source_label' => $payload['source_label'] ?? null,
                'category' => $payload['category'] ?? 'training',
                'submitted_by' => $userId,
            ]
        );

        return $this->serialize($quote->fresh(), true);
    }

    private function defaultQuoteFor(Carbon $date): array
    {
        $index = abs(crc32($date->toDateString())) % count(self::QUOTES);

        return self::QUOTES[$index];
    }

    private function serialize(DailyQuote $quote, bool $isCustom): array
    {
        return [
            'id' => $quote->id,
            'quote_date' => $quote->quote_date?->toDateString(),
            'quote' => $quote->quote,
            'author' => $quote->author,
            'source_label' => $quote->source_label,
            'category' => $quote->category,
            'submitted_by' => $quote->submitted_by,
            'is_custom' => $isCustom,
        ];
    }
}
