<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Services\Seo\BioGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WpSeoController extends Controller
{
    public function __construct(private readonly BioGenerationService $generator) {}

    /**
     * POST /api/wp-svc/seo/generate-bio
     * Called by WordPress plugin (HMAC auth via WpServiceAuth middleware).
     */
    public function generateBio(Request $request): JsonResponse
    {
        if (!config('services.seo_engine.enabled', false)) {
            return response()->json(['message' => 'SEO Engine is disabled in CRM settings.'], 403);
        }

        // Platform identity is enforced by WpServiceAuth; header takes precedence.
        $platformId = (int) $request->attributes->get('platform_id', 0);

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

        // Header platform always wins over body platform_id
        if ($platformId > 0) {
            $data['platform_id'] = $platformId;
        } elseif (empty($data['platform_id'])) {
            throw ValidationException::withMessages([
                'platform_id' => 'Platform identity is required.',
            ]);
        }

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
                'save' => 'save=true requires client_id or wp_post_id.',
            ]);
        }

        try {
            $result = $this->generator->generate($data);
        } catch (\Throwable $e) {
            $eventId = (string) Str::uuid();

            Log::error('seo.wp_generate_bio_failed', [
                'event_id' => $eventId,
                'platform_id' => $data['platform_id'],
                'wp_post_id' => $data['wp_post_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json(array_filter([
                'message' => "Bio generation failed. Reference {$eventId}.",
                'detail' => config('app.debug') ? $e->getMessage() : null,
            ]), 500);
        }

        Log::info('wp-svc.seo.bio_generated', [
            'platform_id'   => $data['platform_id'],
            'wp_post_id'    => $data['wp_post_id'] ?? null,
            'provider_used' => $result['provider_used'],
            'score'         => $result['score'],
        ]);

        return response()->json($result);
    }
}
