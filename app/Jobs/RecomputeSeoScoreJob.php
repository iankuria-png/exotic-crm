<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\ProfileSnapshotBuilder;
use App\Services\Seo\SeoScorer;
use App\Services\WpSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Recomputes the SEO score for a client whose WP bio was edited directly.
 * Triggered by ClientSyncService when it finds seo_quality_score_stale=1.
 */
class RecomputeSeoScoreJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int $clientId,
    ) {}

    public function handle(
        ProfileSnapshotBuilder $snapshotBuilder,
        SeoScorer              $scorer,
    ): void {
        $client = Client::find($this->clientId);
        if (!$client) {
            Log::warning('RecomputeSeoScoreJob: client not found', ['client_id' => $this->clientId]);
            return;
        }

        $wpPostId   = (int) ($client->wp_post_id ?? 0);
        $platformId = (int) $client->platform_id;

        if ($wpPostId <= 0 || $platformId <= 0) {
            Log::warning('RecomputeSeoScoreJob: no wp_post_id or platform_id', ['client_id' => $this->clientId]);
            return;
        }

        // Build snapshot from current WP state
        $snapshot = $snapshotBuilder->fromRequest(null, $wpPostId, $platformId);

        // Fetch current bio from WP to score
        try {
            $wpData  = WpSyncService::forPlatform($platformId)->getClientProfile($wpPostId);
            $bioHtml = (string) ($wpData['post']['content'] ?? '');
        } catch (\Throwable $e) {
            Log::error('RecomputeSeoScoreJob: failed to fetch WP bio', [
                'client_id'  => $this->clientId,
                'wp_post_id' => $wpPostId,
                'error'      => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        $result = $scorer->score($bioHtml, $snapshot);

        // Write score back to WP
        try {
            $wpSync = WpSyncService::forPlatform($platformId);
            $wpSync->writeSeoScore($wpPostId, $result['total'], $result['breakdown']);
        } catch (\Throwable $e) {
            Log::error('RecomputeSeoScoreJob: failed to write score to WP', [
                'client_id'  => $this->clientId,
                'wp_post_id' => $wpPostId,
                'error'      => $e->getMessage(),
            ]);
            $this->fail($e);
            return;
        }

        // Update CRM client
        $client->update([
            'seo_score'            => $result['total'],
            'seo_score_breakdown'  => $result['breakdown'],
            'seo_score_updated_at' => now(),
        ]);

        Log::info('RecomputeSeoScoreJob: completed', [
            'client_id'  => $this->clientId,
            'wp_post_id' => $wpPostId,
            'score'      => $result['total'],
        ]);
    }
}
