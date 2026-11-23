<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Jobs\Concerns\Sheddable;
use App\Models\Client;
use App\Models\TimelineEvent;
use App\Services\Seo\BioGenerationService;
use App\Services\WpSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Writes an AI bio for a profile just created from a scraped lead, so customer service
 * opens a profile with starter copy. No numbers and no contact details: the starter facts
 * (age, height, weight) are placeholders until staff confirm them.
 *
 * Never overwrites: if the profile already has a bio when the job runs, it stands down.
 */
class GenerateLeadProfileBioJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnHeavyQueue, SerializesModels, Sheddable;

    public const GENERATION_OPTIONS = [
        'no_digits' => true,
        'include_contact' => false,
        'contact_channel' => 'none',
    ];

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public int $clientId, public ?int $actorId = null)
    {
        $this->routeToHeavyQueue();
    }

    public function shedCapability(): string
    {
        return 'bulk_bio';
    }

    public function handle(BioGenerationService $generator): void
    {
        if ($this->shedIfDegraded()) {
            return;
        }

        $client = Client::query()->find($this->clientId);
        if (! $client || ! $client->wp_post_id) {
            return;
        }
        if (! config('services.seo_engine.enabled', false)) {
            $this->record($client, 'skipped', 'The SEO engine is switched off.');

            return;
        }

        $postId = (int) $client->wp_post_id;
        $wp = WpSyncService::forPlatform((int) $client->platform_id);
        $current = $wp->getClientBiosPool([$postId])[$postId] ?? null;
        if ($current === null) {
            $this->record($client, 'failed', 'The current bio could not be read, so nothing was written.');

            return;
        }
        if (trim(strip_tags($current)) !== '') {
            $this->record($client, 'skipped', 'The profile already has a bio.');

            return;
        }

        $result = $generator->generate([
            'client_id' => (int) $client->id,
            'platform_id' => (int) $client->platform_id,
            'generation_options' => self::GENERATION_OPTIONS,
        ]);
        $bio = (string) ($result['bio_html'] ?? '');
        if (! empty($result['fallback_used']) || trim(strip_tags($bio)) === '') {
            $this->record($client, 'skipped', 'No AI provider was available. Generate the bio from the profile editor.');

            return;
        }

        $wp->updateClientProfile($postId, ['content' => $bio]);
        try {
            $wp->writeSeoScore($postId, (int) ($result['score'] ?? 0), (array) ($result['breakdown'] ?? []));
        } catch (\Throwable $e) {
            Log::warning('lead_profile_bio.score_write_failed', ['client_id' => $client->id, 'error' => $e->getMessage()]);
        }

        $this->record($client, 'generated', null, [
            'provider' => $result['provider_used'] ?? null,
            'score' => $result['score'] ?? null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $client = Client::query()->find($this->clientId);
        if ($client) {
            $this->record($client, 'failed', 'Bio generation did not finish. Generate it from the profile editor.');
        }
        Log::warning('lead_profile_bio.failed', ['client_id' => $this->clientId, 'error' => $e->getMessage()]);
    }

    private function record(Client $client, string $status, ?string $note, array $extra = []): void
    {
        TimelineEvent::query()->create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'ai_bio_'.$status,
            'actor_id' => $this->actorId,
            'content' => array_filter([
                'source' => 'lead_scraper_conversion',
                'note' => $note,
                ...$extra,
            ], static fn ($value) => $value !== null),
        ]);
    }
}
