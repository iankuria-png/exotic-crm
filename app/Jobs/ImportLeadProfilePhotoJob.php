<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsOnHeavyQueue;
use App\Jobs\Concerns\Sheddable;
use App\Models\Client;
use App\Models\Lead;
use App\Models\TimelineEvent;
use App\Services\ClientProfileImageService;
use App\Services\Scraper\LeadPhotoDownloader;
use App\Services\WpSyncService;
use App\Support\LeadScrapedPhoto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gives a profile created from a scraped lead the photo its listing showed, as main image,
 * so customer service does not open an empty profile. It is a stand-in: the timeline note
 * says to replace it with the client's own photos (it may carry the listing site's mark).
 *
 * Never adds to a profile that already has any media, and never runs without a photo URL.
 */
class ImportLeadProfilePhotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsOnHeavyQueue, SerializesModels, Sheddable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $clientId, public int $leadId, public ?int $actorId = null)
    {
        $this->routeToHeavyQueue();
    }

    public function shedCapability(): string
    {
        return 'lead_photo';
    }

    public function handle(LeadPhotoDownloader $downloader): void
    {
        if ($this->shedIfDegraded()) {
            return;
        }

        $client = Client::query()->find($this->clientId);
        if (! $client || ! $client->wp_post_id) {
            return;
        }
        $url = LeadScrapedPhoto::urlFor($this->leadId);
        if ($url === null) {
            $this->record($client, 'skipped', 'The scraped listing had no photo.');

            return;
        }

        $postId = (int) $client->wp_post_id;
        $wp = WpSyncService::forPlatform((int) $client->platform_id);
        try {
            $media = app(ClientProfileImageService::class)->normalizeMediaItems($wp->getClientMedia($postId));
        } catch (\Throwable $e) {
            $this->record($client, 'failed', 'The profile photos could not be read, so nothing was added.');
            Log::warning('lead_profile_photo.media_read_failed', ['client_id' => $client->id, 'error' => $e->getMessage()]);

            return;
        }
        if ($media !== []) {
            $this->record($client, 'skipped', 'The profile already has photos.');

            return;
        }

        $lead = Lead::query()->find($this->leadId);
        $photo = $downloader->downloadBest($url, $lead?->source_url);
        if (! $photo['ok']) {
            $this->record($client, 'failed', $photo['error'].' Add a photo from the profile editor.', [
                'error_code' => $photo['error_code'],
                'source_host' => parse_url($url, PHP_URL_HOST) ?: null,
            ]);

            return;
        }

        $path = tempnam(sys_get_temp_dir(), 'lead-photo-');
        try {
            file_put_contents($path, $photo['bytes']);
            $name = trim((string) $client->name);
            $result = $wp->uploadClientMediaFile(
                $postId,
                $path,
                (Str::slug($name) ?: 'profile').'-'.$client->id.'.'.$photo['extension'],
                $photo['mime'],
                true,
                $name !== '' ? $name : null
            );
        } finally {
            @unlink($path);
        }

        $this->record($client, 'imported', 'Copied from the scraped listing as a stand-in. Replace it with the client\'s own photos.', [
            'attachment_id' => isset($result['id']) ? (int) $result['id'] : null,
            'source_host' => parse_url($photo['final_url'], PHP_URL_HOST) ?: null,
            'width' => $photo['width'],
            'height' => $photo['height'],
            'full_size' => $photo['final_url'] !== $url ? true : null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $client = Client::query()->find($this->clientId);
        if ($client) {
            $this->record($client, 'failed', 'The listing photo could not be added. Add a photo from the profile editor.');
        }
        Log::warning('lead_profile_photo.failed', ['client_id' => $this->clientId, 'error' => $e->getMessage()]);
    }

    private function record(Client $client, string $status, string $note, array $extra = []): void
    {
        TimelineEvent::query()->create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'lead_photo_'.$status,
            'actor_id' => $this->actorId,
            // TimelineEvent has no automatic timestamps.
            'created_at' => now(),
            'content' => array_filter([
                'source' => 'lead_scraper_conversion',
                'lead_id' => $this->leadId,
                'note' => $note,
                ...$extra,
            ], static fn ($value) => $value !== null),
        ]);
    }
}
