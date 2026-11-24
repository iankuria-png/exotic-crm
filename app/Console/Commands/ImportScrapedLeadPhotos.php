<?php

namespace App\Console\Commands;

use App\Jobs\ImportLeadProfilePhotoJob;
use App\Models\Client;
use App\Models\Lead;
use App\Models\TimelineEvent;
use App\Support\LeadScrapedPhoto;
use Illuminate\Console\Command;

/**
 * Backfill for profiles converted from scraped leads before the photo import existed.
 * Dry run by default; --apply queues one ImportLeadProfilePhotoJob per profile, and the job
 * itself re-checks WordPress and stands down on any profile that already has media.
 */
class ImportScrapedLeadPhotos extends Command
{
    protected $signature = 'leads:import-scraped-photos
        {--platform= : Only this market (platform id)}
        {--client=* : Only these client ids}
        {--limit=200 : Most profiles to queue in one run}
        {--apply : Queue the imports (default is a dry run)}';

    protected $description = 'Copy the scraped listing photo onto converted lead profiles that have no photo';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $clientIds = array_values(array_filter(array_map('intval', (array) $this->option('client'))));

        $leads = Lead::query()
            ->whereNotNull('converted_client_id')
            ->when($this->option('platform'), fn ($q, $platform) => $q->where('platform_id', (int) $platform))
            ->when($clientIds !== [], fn ($q) => $q->whereIn('converted_client_id', $clientIds))
            ->orderBy('id')
            ->get(['id', 'platform_id', 'name', 'converted_client_id']);

        $photos = LeadScrapedPhoto::urlsFor($leads->pluck('id')->all());
        $leads = $leads->filter(fn (Lead $lead) => isset($photos[$lead->id]));
        $clients = Client::query()->whereIn('id', $leads->pluck('converted_client_id'))->get()->keyBy('id');
        $done = TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('event_type', 'lead_photo_imported')
            ->whereIn('entity_id', $clients->keys())
            ->pluck('entity_id')->map(fn ($id) => (int) $id)->flip();

        $rows = [];
        $skipped = ['no WordPress profile' => 0, 'already has a photo' => 0, 'already imported' => 0];
        foreach ($leads as $lead) {
            $client = $clients[$lead->converted_client_id] ?? null;
            if (! $client || ! $client->wp_post_id || (int) $client->platform_id !== (int) $lead->platform_id) {
                $skipped['no WordPress profile']++;
            } elseif ($client->main_image_url || $client->display_image_url) {
                $skipped['already has a photo']++;
            } elseif ($done->has((int) $client->id)) {
                $skipped['already imported']++;
            } else {
                $rows[] = [$lead, $client];
            }
        }
        $rows = array_slice($rows, 0, $limit);

        $this->info(($apply ? 'Queueing' : 'Dry run:').' '.count($rows).' profile(s) to receive their scraped listing photo.');
        $this->table(['Client', 'Market', 'Name', 'Lead', 'Photo host'], array_map(fn ($row) => [
            $row[1]->id, $row[1]->platform_id, $row[1]->name, $row[0]->id, parse_url($photos[$row[0]->id], PHP_URL_HOST),
        ], $rows));
        foreach ($skipped as $reason => $count) {
            if ($count > 0) {
                $this->line("Skipped {$count}: {$reason}.");
            }
        }

        if (! $apply) {
            $this->line('Nothing was queued. Re-run with --apply to queue these imports.');

            return self::SUCCESS;
        }
        foreach ($rows as [$lead, $client]) {
            ImportLeadProfilePhotoJob::dispatch((int) $client->id, (int) $lead->id, null);
        }
        $this->info('Queued '.count($rows).' import(s) on the heavy queue. Each result is on the client timeline.');

        return self::SUCCESS;
    }
}
