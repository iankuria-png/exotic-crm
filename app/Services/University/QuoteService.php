<?php

namespace App\Services\University;

use Carbon\CarbonImmutable;

/**
 * Quote of the Day service.
 * Quotes are stored in-code (no DB) — one is picked deterministically per calendar day
 * so the whole team sees the same quote, and it rotates at midnight in app timezone.
 */
class QuoteService
{
    public function today(): array
    {
        $quotes = $this->quotes();
        $dayOfYear = (int) CarbonImmutable::today()->dayOfYear;
        $index = $dayOfYear % count($quotes);

        return $quotes[$index];
    }

    public function all(): array
    {
        return $this->quotes();
    }

    /**
     * @return array<int, array{quote:string, author:string, kind:string, accent:string}>
     */
    private function quotes(): array
    {
        return [
            ['quote' => 'No one can effectively sell, support, market, or improve a product they do not fully understand.', 'author' => 'Julian Papa', 'kind' => 'Sales wisdom', 'accent' => 'teal'],
            ['quote' => 'Ni hayo tu kwa sasa.', 'author' => 'Wahenga', 'kind' => 'Swahili proverb', 'accent' => 'amber'],
            ['quote' => 'Asiyesikia la mkuu huvunjika guu.', 'author' => 'Wahenga', 'kind' => 'Swahili proverb', 'accent' => 'amber'],
            ['quote' => 'The customer never cared about your script — they care about whether you actually heard them.', 'author' => 'Sales Floor', 'kind' => 'Sales wisdom', 'accent' => 'teal'],
            ['quote' => 'Haraka haraka haina baraka.', 'author' => 'Wahenga', 'kind' => 'Swahili proverb', 'accent' => 'amber'],
            ['quote' => 'Diagnose before you discount. Always.', 'author' => 'Renewal Playbook', 'kind' => 'Renewals', 'accent' => 'emerald'],
            ['quote' => 'Penye nia pana njia.', 'author' => 'Wahenga', 'kind' => 'Swahili proverb', 'accent' => 'amber'],
            ['quote' => 'If you cannot explain it simply, you cannot sell it. And the customer will quietly walk away.', 'author' => 'Sales Floor', 'kind' => 'Sales wisdom', 'accent' => 'teal'],
            ['quote' => 'Asiyeuliza hanalo ajifunzalo.', 'author' => 'Wahenga', 'kind' => 'Swahili proverb', 'accent' => 'amber'],
            ['quote' => 'Every refund request is a fix in disguise. Offer five minutes before you offer the money.', 'author' => 'F-Series Playbook', 'kind' => 'Failure recovery', 'accent' => 'rose'],
            ['quote' => 'Pole pole ndio mwendo.', 'author' => 'Wahenga', 'kind' => 'Swahili proverb', 'accent' => 'amber'],
            ['quote' => 'The CRM is the front door. If it is not logged, it did not happen.', 'author' => 'Operations', 'kind' => 'Discipline', 'accent' => 'indigo'],
            ['quote' => 'Mtu ni watu.', 'author' => 'Wahenga', 'kind' => 'Swahili proverb', 'accent' => 'amber'],
            ['quote' => 'Visibility they already have. Lead quality they don\'t. Always lead with what they lack.', 'author' => 'Cross-listing Playbook', 'kind' => 'Objection handling', 'accent' => 'teal'],
            ['quote' => 'Akili ni mali.', 'author' => 'Wahenga', 'kind' => 'Swahili proverb', 'accent' => 'amber'],
            ['quote' => 'The renewal you didn\'t make is more expensive than the discount you didn\'t give.', 'author' => 'Retention Math', 'kind' => 'Retention', 'accent' => 'emerald'],
            ['quote' => 'Cheka na duniani; itakucheka.', 'author' => 'Wahenga', 'kind' => 'Swahili proverb', 'accent' => 'amber'],
            ['quote' => 'VVIP is capped per city. Use the scarcity honestly — it is the truth.', 'author' => 'Package Mastery', 'kind' => 'Product knowledge', 'accent' => 'indigo'],
            ['quote' => 'Mtu hukumbushwa, hatazaliwa akijua.', 'author' => 'Wahenga', 'kind' => 'Swahili proverb', 'accent' => 'amber'],
            ['quote' => 'A clean "no" preserves the relationship better than a desperate "let me see what I can do" that comes back with the same number.', 'author' => 'Discount Discipline', 'kind' => 'Discipline', 'accent' => 'rose'],
        ];
    }
}
