<?php

namespace App\Services\Scraper;

use App\Models\Lead;
use App\Models\ScraperSource;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\LeadAssignmentService;
use App\Services\ScraperSourceService;

/**
 * Dedupes candidates against existing leads (same modes as the original scraper) and
 * creates leads with a lead_scraped timeline event carrying the extra fields.
 */
class LeadImporter
{
    public function __construct(private readonly LeadAssignmentService $assignment)
    {
    }

    public function key(ScraperSource $source, array $c): string
    {
        return match ($this->mode($source)) {
            'phone_only' => $c['phone'] ? 'phone:' . $c['phone'] : '',
            'email_only' => $c['email'] ? 'email:' . $c['email'] : '',
            'source_url' => $c['profile_url'] ? 'url:' . strtolower($c['profile_url']) : '',
            default => $c['phone'] ? 'phone:' . $c['phone'] : ($c['email'] ? 'email:' . $c['email'] : ($c['profile_url'] ? 'url:' . strtolower($c['profile_url']) : '')),
        };
    }

    public function exists(ScraperSource $source, array $c): bool
    {
        $query = Lead::query()->where('platform_id', (int) $source->platform_id);
        $mode = $this->mode($source);

        if ($mode === 'phone_only') {
            return $c['phone'] ? $query->where('phone_normalized', $c['phone'])->exists() : false;
        }
        if ($mode === 'email_only') {
            return $c['email'] ? $query->where('email', $c['email'])->exists() : false;
        }
        if ($mode === 'source_url') {
            return $c['profile_url'] ? $query->where('source_url', $c['profile_url'])->exists() : false;
        }
        if (!$c['phone'] && !$c['email'] && !$c['profile_url']) {
            return false;
        }

        return $query->where(function ($q) use ($c) {
            if ($c['phone']) {
                $q->orWhere('phone_normalized', $c['phone']);
            }
            if ($c['email']) {
                $q->orWhere('email', $c['email']);
            }
            if ($c['profile_url']) {
                $q->orWhere('source_url', $c['profile_url']);
            }
        })->exists();
    }

    public function importable(array $c): bool
    {
        return ($c['name'] ?? null) !== null && (($c['phone'] ?? null) !== null || ($c['email'] ?? null) !== null || ($c['profile_url'] ?? null) !== null);
    }

    public function create(ScraperSource $source, array $c, User $actor, ?int $runId = null): Lead
    {
        $assignedTo = $this->assignment->assignOwnerId((int) $source->platform_id, [
            'phone_normalized' => $c['phone'],
            'email' => $c['email'],
            'name' => $c['name'],
        ], null);

        $lead = Lead::query()->create([
            'platform_id' => (int) $source->platform_id,
            'name' => $c['name'],
            'phone_normalized' => $c['phone'],
            'email' => $c['email'],
            'source_url' => $c['profile_url'] ?? $source->source_url,
            'source' => 'import',
            'status' => 'new',
            'assigned_to' => $assignedTo,
        ]);

        TimelineEvent::query()->create([
            'platform_id' => (int) $source->platform_id,
            'entity_type' => 'lead',
            'entity_id' => (int) $lead->id,
            'event_type' => 'lead_scraped',
            'actor_id' => (int) $actor->id,
            'content' => array_filter([
                'scraper_source_id' => (int) $source->id,
                'scraper_run_id' => $runId,
                'source_url' => $c['profile_url'] ?? null,
                'listing_url' => $source->source_url,
                'template_key' => $source->template_key,
                'whatsapp' => $c['whatsapp'] ?? null,
                'city' => $c['city'] ?? null,
                'age' => $c['age'] ?? null,
                'image_url' => $c['image_url'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'created_at' => now(),
        ]);

        return $lead;
    }

    private function mode(ScraperSource $source): string
    {
        return in_array($source->dedupe_mode, ScraperSourceService::DEDUPE_MODES, true) ? (string) $source->dedupe_mode : 'phone_or_email';
    }
}
