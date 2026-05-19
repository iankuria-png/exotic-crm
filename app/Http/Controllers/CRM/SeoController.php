<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\ProfileSnapshotBuilder;
use App\Services\Seo\SeoScorer;
use App\Services\WpSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SeoController extends Controller
{
    public function __construct(
        private readonly BioGenerationService   $generator,
        private readonly ProfileSnapshotBuilder $snapshotBuilder,
        private readonly SeoScorer              $scorer,
    ) {}

    /**
     * POST /api/crm/seo/generate-bio
     * Called by CRM SPA (Sanctum auth).
     */
    public function generateBio(Request $request): JsonResponse
    {
        if (!config('services.seo_engine.enabled', false)) {
            return response()->json(['message' => 'SEO Engine is disabled. Enable it under Settings → SEO Engine and save the settings.'], 403);
        }

        $data = $request->validate([
            'client_id'        => 'nullable|integer|min:1',
            'wp_post_id'       => 'nullable|integer|min:1',
            'platform_id'      => 'nullable|integer|min:1',
            'profile_snapshot' => 'nullable|array',
            'save'             => 'nullable|boolean',
            'force_provider'   => 'nullable|string|in:claude,openai,gemini,deepseek',
            'generation_options' => 'nullable|array',
            'generation_options.tone' => 'nullable|string|max:180',
            'generation_options.temperament' => 'nullable|string|max:180',
            'generation_options.min_words' => 'nullable|integer|min:25|max:500',
            'generation_options.max_words' => 'nullable|integer|min:40|max:700',
            'generation_options.max_characters' => 'nullable|integer|min:200|max:5000',
            'generation_options.max_services' => 'nullable|integer|min:0|max:20',
            'generation_options.include_location' => 'nullable|boolean',
            'generation_options.include_services' => 'nullable|boolean',
            'generation_options.include_contact' => 'nullable|boolean',
            'generation_options.contact_channel' => 'nullable|string|in:none,phone,whatsapp,both',
            'generation_options.custom_prompt' => 'nullable|string|max:2000',
        ]);

        $this->validateGenerationRequest($data);

        $platformId = $this->resolvePlatformId($data);

        try {
            $result = $this->generator->generate(array_merge($data, [
                'platform_id' => $platformId,
            ]));
        } catch (\Throwable $e) {
            $eventId = (string) Str::uuid();

            Log::error('seo.generate_bio_failed', [
                'event_id' => $eventId,
                'client_id' => $data['client_id'] ?? null,
                'wp_post_id' => $data['wp_post_id'] ?? null,
                'platform_id' => $platformId,
                'error' => $e->getMessage(),
            ]);

            return response()->json(array_filter([
                'message' => "Bio generation failed. Reference {$eventId}.",
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]), 500);
        }

        if (!empty($data['save'])) {
            $this->persistResult($data, $result);
        }

        return response()->json($result);
    }

    // -------------------------------------------------------------------------

    private function validateGenerationRequest(array $data): void
    {
        $hasClient   = !empty($data['client_id']);
        $hasPost     = !empty($data['wp_post_id']);
        $hasSnapshot = !empty($data['profile_snapshot']);

        if (!$hasClient && !$hasPost && !$hasSnapshot) {
            throw ValidationException::withMessages([
                'request' => 'At least one of client_id, wp_post_id, or profile_snapshot is required.',
            ]);
        }

        if (!empty($data['save']) && !$hasClient && !$hasPost) {
            throw ValidationException::withMessages([
                'save' => 'save=true requires client_id or wp_post_id to anchor the result.',
            ]);
        }
    }

    private function resolvePlatformId(array $data): int
    {
        if (!empty($data['platform_id'])) {
            return (int) $data['platform_id'];
        }

        if (!empty($data['client_id'])) {
            $client = Client::findOrFail((int) $data['client_id']);
            return (int) $client->platform_id;
        }

        return 0;
    }

    private function persistResult(array $data, array $result): void
    {
        $clientId = !empty($data['client_id']) ? (int) $data['client_id'] : null;
        $wpPostId = !empty($data['wp_post_id']) ? (int) $data['wp_post_id'] : null;

        if ($clientId !== null) {
            $client = Client::find($clientId);
            if ($client && $wpPostId === null && $client->wp_post_id) {
                $wpPostId = (int) $client->wp_post_id;
            }
        }

        if ($wpPostId !== null) {
            $platformId = !empty($data['platform_id'])
                ? (int) $data['platform_id']
                : ($clientId ? (int) Client::find($clientId)?->platform_id : 0);

            if ($platformId > 0) {
                try {
                    $wpSync = WpSyncService::forPlatform($platformId);
                    $wpSync->updateClientProfile($wpPostId, ['content' => $result['bio_html']]);
                    $wpSync->writeSeoScore($wpPostId, $result['score'], $result['breakdown']);
                } catch (\Throwable $e) {
                    Log::error('SeoController: failed to persist to WP', [
                        'wp_post_id' => $wpPostId,
                        'error'      => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($clientId !== null) {
            Client::where('id', $clientId)->update([
                'seo_score'            => $result['score'],
                'seo_score_breakdown'  => json_encode($result['breakdown']),
                'seo_score_updated_at' => now(),
            ]);
        }
    }
}
