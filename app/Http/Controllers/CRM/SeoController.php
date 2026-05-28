<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\SeoBioFeedback;
use App\Services\Seo\BioGenerationService;
use App\Services\Seo\FeedbackInsightService;
use App\Services\Seo\ProfileSnapshotBuilder;
use App\Services\Seo\SeoScorer;
use App\Services\WpSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SeoController extends Controller
{
    public function __construct(
        private readonly BioGenerationService   $generator,
        private readonly ProfileSnapshotBuilder $snapshotBuilder,
        private readonly SeoScorer              $scorer,
        private readonly FeedbackInsightService $feedbackInsight,
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
            'generation_options.language' => ['nullable', 'string', Rule::in(array_keys(\App\Services\Seo\BioGenerationService::SUPPORTED_LANGUAGES))],
            'refinements'      => 'nullable|array|max:6',
            'refinements.*'    => ['string', Rule::in(array_keys(\App\Services\Seo\BioGenerationService::REFINEMENT_PRESETS))],
            'previous_bio'     => 'nullable|string|max:6000',
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

    /**
     * POST /api/crm/seo/feedback
     * Log the editor's reaction to a generated bio.
     *
     * This rows into seo_bio_feedback, and the most recent rows per platform
     * are injected into future system prompts so the LLM learns the editors'
     * preferences.
     */
    public function feedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform_id'    => 'required|integer|min:1',
            'client_id'      => 'nullable|integer|min:1',
            'wp_post_id'     => 'nullable|integer|min:1',
            'provider_used'  => 'nullable|string|max:40',
            'rating'         => 'nullable|integer|min:-1|max:1',
            'tag'            => ['nullable', 'string', Rule::in(SeoBioFeedback::ALLOWED_TAGS)],
            'comment'        => 'nullable|string|max:2000',
            'accepted'       => 'nullable|boolean',
            'score'          => 'nullable|integer|min:0|max:100',
            'bio_html'       => 'nullable|string|max:20000',
            'generation_options' => 'nullable|array',
        ]);

        $row = SeoBioFeedback::create([
            'platform_id'        => (int) $data['platform_id'],
            'client_id'          => $data['client_id'] ?? null,
            'wp_post_id'         => $data['wp_post_id'] ?? null,
            'user_id'            => $request->user()?->id,
            'provider_used'      => $data['provider_used'] ?? null,
            'rating'             => (int) ($data['rating'] ?? 0),
            'tag'                => $data['tag'] ?? null,
            'comment'            => $data['comment'] ?? null,
            'accepted'           => (bool) ($data['accepted'] ?? false),
            'score'              => $data['score'] ?? null,
            'generation_options' => $data['generation_options'] ?? null,
            'bio_html'           => $data['bio_html'] ?? null,
        ]);

        $this->feedbackInsight->forgetPlatformCache((int) $data['platform_id']);

        Log::info('seo.feedback.recorded', [
            'id'          => $row->id,
            'platform_id' => $row->platform_id,
            'rating'      => $row->rating,
            'tag'         => $row->tag,
            'accepted'    => $row->accepted,
            'user_id'     => $row->user_id,
        ]);

        return response()->json([
            'id'      => $row->id,
            'message' => 'Feedback recorded.',
        ]);
    }

    /**
     * GET /api/crm/seo/feedback/summary?platform_id=1
     * What recent feedback is currently steering the prompt for a platform.
     * Used by the Settings page to show editors a transparency view.
     */
    public function feedbackSummary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform_id' => 'required|integer|min:1',
        ]);

        return response()->json($this->feedbackInsight->summaryForPlatform((int) $data['platform_id']));
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
