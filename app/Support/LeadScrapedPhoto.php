<?php

namespace App\Support;

use App\Models\TimelineEvent;

/**
 * The listing photo the Lead Scraper saw for a lead. It is kept only on the lead's
 * `lead_scraped` timeline event (leads have no photo column); the latest event wins.
 */
final class LeadScrapedPhoto
{
    /**
     * @param  array<int, int>  $leadIds
     * @return array<int, string> lead id => photo URL, only for leads that have one
     */
    public static function urlsFor(array $leadIds): array
    {
        if ($leadIds === []) {
            return [];
        }

        $urls = [];
        TimelineEvent::query()
            ->where('entity_type', 'lead')
            ->where('event_type', 'lead_scraped')
            ->whereIn('entity_id', array_values(array_unique(array_map('intval', $leadIds))))
            ->orderBy('id')
            ->get(['entity_id', 'content'])
            ->each(function (TimelineEvent $event) use (&$urls) {
                $url = trim((string) data_get($event->content, 'image_url', ''));
                if ($url !== '' && preg_match('#^https?://#i', $url)) {
                    $urls[(int) $event->entity_id] = $url;
                }
            });

        return $urls;
    }

    public static function urlFor(int $leadId): ?string
    {
        return self::urlsFor([$leadId])[$leadId] ?? null;
    }
}
